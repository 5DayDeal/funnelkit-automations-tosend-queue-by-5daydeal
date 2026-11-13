# FunnelKit Automations toSend Queue Plugin by 5DayDeal.com

A WordPress plugin that sends large [FunnelKit Automations](https://funnelkit.com/wordpress-marketing-automation-crm/) broadcasts through [toSend](https://tosend.com) at the full speed toSend allows, instead of one email at a time. Scheduled broadcasts are built ahead of time and go out at the minute they're scheduled for.

On our own site, a 53,000-contact broadcast went from **about 2.5 hours to about 19 minutes**.

**If this saves you time, please star the repo as a thank you.** It helps other FunnelKit and toSend users find it.

## The story: from 2.5 hours to 19 minutes

We send large FunnelKit broadcasts (50,000+ contacts) for [5DayDeal.com](https://5daydeal.com) and its brands. Each step below came from watching a real send and fixing the slowest part.

| When (2026) | What changed | 53,000-email broadcast |
|---|---|---|
| Before | FunnelKit alone, through FluentSMTP: one email built, one `wp_mail()` call and one API request at a time | about 2 h 34 m (about 6 emails a second, average of 10 sends) |
| Oct 12 | Queue: FunnelKit hands each email to a queue, which sends 100 at a time through toSend's batch API | 1 h 48 m. Sending was fast, but FunnelKit could only build about 8 emails a second |
| Oct 13 | Pre-building: the emails are built ahead of time in 4 parallel workers and released at the scheduled minute | **6 m 4 s** (about 148 a second) |
| Oct 28 | Rebuilt for FunnelKit 3.9's new sending flow | about 180 a second once released |
| Oct 29 | toSend added a per-team limit of 30 emails a second during sales season, to protect everyone's sending. The queue paces itself to stay under it | about 32 minutes |
| Oct 30 | A rescheduled broadcast keeps its pre-built emails, and small edits are patched in instead of rebuilding everything | |
| Oct 31 | toSend added `Idempotency-Key` to its batch API at our request. A batch that times out is resent safely, never sent twice | |
| Nov 5 to 9 | toSend raised the limit to **50 emails a second** and now reports each team's real limit in `/v2/info`. The queue reads it and sends at 95% of it (47 a second) | **about 19 minutes** |

When toSend raises the limit again, the queue picks up the new number by itself within 10 minutes.

Thank you to the toSend team, who added idempotency keys and the live limit within days of us asking, and to the FunnelKit team for the mail handler filter in 3.9 that makes this possible.

## What it does

- **Queues broadcast emails** instead of sending them one at a time. On FunnelKit 3.9 and later it uses FunnelKit's own `bwfcrm_override_broadcast_mail_handler` filter. On older versions it catches broadcast `wp_mail()` calls through `pre_wp_mail`. Order emails, password resets and automations are left alone.
- **Sends through toSend's batch endpoint** (`/v2/emails/batch`, 100 emails a request), paced at 95% of your team's per-second limit, which it reads from toSend's `/v2/info` every 10 minutes. It honors `Retry-After` on a 429.
- **Retries safely.** Every batch carries an `Idempotency-Key`. A batch that timed out or got a 5xx is resent with the same body and key, so it is never sent twice.
- **Pre-builds scheduled broadcasts** (optional). Up to an hour before the send time, once FunnelKit has prepared the broadcast, parallel workers run FunnelKit's own sending early and hold every email until the scheduled minute. Reschedules, sender changes and small text edits keep the pre-built emails; bigger changes are rebuilt.
- **Skips anyone who unsubscribes** between their email being built and being sent.
- **Keeps FunnelKit's reports right** by recording the time toSend accepted each email, and marking rejections as failed with toSend's message.
- **Light open pixel** (optional): a must-use plugin that loads only FunnelKit's plugins for open-tracking requests, so thousands of opens after a big send don't each load the whole site.
- **Status page** at Tools > toSend queue, plus `wp chtq` commands.

It uses the toSend connection already set up in FluentSMTP and stores no keys of its own.

`BEST-PRACTICES.md` explains everything we learned, and most of it applies even if you don't use the plugin.

## Requirements

- WordPress with FunnelKit Automations Pro (broadcasts). Pre-building is built for FunnelKit 3.9, and it switches itself off if FunnelKit's internals don't match what it expects (`wp chtq status` shows `funnelkit=compatible` or the problem).
- FluentSMTP with a toSend connection as the default connection.
- WP-CLI and real cron on the server (system cron or the site user's crontab). Shared hosting without cron access won't work.
- PHP 7.4 or later with curl.

## Install

1. Download the latest release zip, or clone this repo into `wp-content/plugins/funnelkit-automations-tosend-queue-by-5daydeal`, and activate the plugin. It does nothing until you turn it on.
2. Add the cron lines from `cron-example.txt`, with your paths, as the site user.
3. Check it: `wp chtq status`.
4. Turn on queueing: `wp chtq enable`.
5. Check the speed: `wp chtq status` shows the rate (default `auto`, 95% of toSend's limit). `wp chtq set rate <n>` overrides it.
6. Optional: `wp chtq set prebuild on` (only broadcasts with at least `prebuild_min` contacts, default 1000), and `wp chtq pixel on`.
7. Send a broadcast to a small test segment first and watch `drain.log`.

To switch off: `wp chtq disable`. Broadcasts go back through FluentSMTP, and anything already queued still sends.

Deactivating keeps the plugin's tables and settings. Deleting it from the Plugins screen removes them.

## Commands

`wp chtq status | enable | disable | set <key> <value> | hold | release | resume | requeue | drain | tick | work | jobs | prune | pixel on|off`

Settings for `set`: `rate` (`auto`, emails a second, or 0 for no limit), `workers`, `prebuild`, `prebuild_min`, `build_workers`.

## For hosts

- The jobs must run from real cron, each under its own `flock`, as the site user. WP-Cron is not enough: the drain runs for minutes at a time.
- If `wp` starts with `#!/usr/bin/env php` and php is not in cron's default PATH, set `PATH` at the top of the crontab.
- Run the jobs on exactly one server per database. Two servers draining the same queue would send twice. When moving a site, start the new jobs and stop the old ones together.
- Keep the log folder somewhere your panel doesn't reset ownership on.
- Load during a 53,000-email send: one PHP process sending, plus the 4 build workers in the hour before a scheduled broadcast. The light open pixel removes most of the load from the opens that follow.

## About 5DayDeal.com

[5DayDeal.com](https://5daydeal.com) runs limited-time bundles of courses, presets and tools for photographers, videographers and other creatives, and gives 10% of every sale to charity. We send a lot of email during each sale, which is why we built this.

This plugin was built by **Griffin Stewart** at 5DayDeal.com. If it helps you, a star on this repo is the best thank you.

## Support and contributions

Shared as is, free to use and adapt under the GPL-2.0-or-later license. Issues and pull requests are welcome, but there's no guaranteed support. Please test on a staging copy before using it on a live site.

FunnelKit and toSend are trademarks of their owners. This project isn't affiliated with or endorsed by either company.
