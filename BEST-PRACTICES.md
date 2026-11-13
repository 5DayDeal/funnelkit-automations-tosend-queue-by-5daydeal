# FunnelKit + toSend: what we learned

Notes from making 53,000-contact FunnelKit broadcasts fast and reliable on toSend. Most of this applies to any integration with toSend's batch API, with or without our plugin.

## Why FunnelKit broadcasts are slow by default

FunnelKit sends each broadcast email on its own through `wp_mail()`, and FluentSMTP makes one API request for each. On our server that was about 6 emails a second, so a 53,000-contact broadcast took about 2.5 hours. Most of that time goes on WordPress work and request round trips for each email, not on toSend.

FunnelKit also prepares a broadcast (works out the audience and creates a record for each contact) only once it is due. For a large list that preparation alone took several minutes, so "scheduled for 10:00" really started sending later.

## Sending

1. **Use the batch endpoint.** `/v2/emails/batch` with 100 emails a request. One batch request costs about the same as one single-email request.
2. **Pace to the team limit, don't push and back off.** toSend sets a per-team limit (50 emails a second as of November 2026) and reports it in `/v2/info` as `account.limit_per_second`, so read it rather than hard-coding it. We send one 100-email batch, then wait until 100 / rate seconds have passed since it started, at 95% of the limit. Parallel requests only help when the limit is higher.
3. **Treat a 429 as "nothing was accepted".** Put the whole batch back without counting it as a failed attempt, and wait for `Retry-After` (5 seconds today).
4. **Send an `Idempotency-Key` with every batch.** Make it unique per batch (we use the queue's claim id). If a request times out after upload or gets a 5xx, resend the same body with the same key. toSend returns its first answer (`Idempotent-Replayed: true`, same `message_id`s) if it had the batch, and processes it as new if it didn't. Before keys existed, the only safe choice after a timeout was to not resend and check by hand.
5. **Keep the body byte-for-byte identical on a retry.** Keep the encoded JSON in memory and resend that. Don't rebuild it from the rows: the same key with a different body gets a 422.
6. **Read the per-item results of a 207.** Mark each email sent or failed on its own. To resend only the failed ones, send them as a new batch with a new key.
7. **Never send a second copy just to be safe.** If a batch still has no clear answer after several same-key tries, mark it as unknown and check toSend's dashboard before resending.

## Getting broadcasts out on time

8. **Build scheduled broadcasts ahead of time.** We run FunnelKit's own send process early in several parallel workers, store the finished emails, and release them at the scheduled minute.
9. **Keep pre-built emails when only small things change.** When a broadcast is rescheduled, FunnelKit's cleanup deletes only unsent records, and its new preparation skips contacts who already have one. So a reschedule doesn't have to throw the work away. Sender and reply-to changes, and one small text edit, can be patched into the stored emails. Audience and design changes are rebuilt.
10. **Check unsubscribes again right before sending.** Pre-built emails can be hours old. Skip anyone who unsubscribed after their email was built, but leave the rest of the audience to FunnelKit, which may deliberately include people (for example, non-marketing broadcasts).

## Keeping FunnelKit's reports right

11. **Update FunnelKit's records when toSend accepts an email**, with the time it was accepted, and mark rejections as failed with toSend's message. Then FunnelKit's broadcast stats, opens and clicks keep working.
12. **Apply FluentSMTP's From rules** (force From email and name) the same way FluentSMTP would, so a queued email looks exactly like one sent without the queue.

## Server

13. **Run the sender from system cron, not WP-Cron**, with a lock (`flock`) so only one copy runs. WP-Cron depends on site traffic and can start several copies at once.
14. **Lighten the open pixel.** A big broadcast causes thousands of open-tracking requests in a few minutes, and each one normally loads every plugin on the site. FunnelKit records the open on `plugins_loaded` and exits, so a small must-use plugin that loads only FunnelKit's plugins for those requests cuts the server load sharply.
15. **Keep keys in one place.** Our plugin reads the toSend key from FluentSMTP's settings and stores none of its own.

## FunnelKit issues we found and reported (Oct 2026)

- **Duplicate record codes.** When two broadcasts prepare the same contact in the same second, the second record's code collides and isn't saved, but FunnelKit still counts that contact as done, so they never get the email. Only our test broadcasts hit it, but it can hit two broadcasts scheduled for the same minute. Space them at least a minute apart until FunnelKit fixes it.
- **Reschedules restart preparation**, even when nothing but the time changed (see point 9).

## Ideas for toSend

- An idempotency option in the official SDKs and in FluentSMTP's toSend driver.
