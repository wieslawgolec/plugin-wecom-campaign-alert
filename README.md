# WeCom Campaign Alert

[![Tests](https://github.com/wieslawgolec/plugin-wecom-campaign-alert/actions/workflows/tests.yml/badge.svg)](https://github.com/wieslawgolec/plugin-wecom-campaign-alert/actions/workflows/tests.yml)

**Mautic 7** plugin that sends real-time **WeCom (企业微信)** group-robot alerts during marketing campaigns.

| | |
|---|---|
| **Bundle** | `WeComCampaignAlertBundle` |
| **Version** | 1.0.3 |
| **PHP** | ≥ 8.2 (CI: 8.2, 8.5, 8.6) |
| **PHPUnit** | 11.5 |
| **License** | GPL-3.0-or-later |

---

## Features

| Alert | When | Dedup |
|-------|------|-------|
| **Email send** | Campaign `email.send` starts delivering | Once per `(campaign, email)` |
| **Email open** | First open of a campaign email | Once per `(campaign, email)` |
| **Publish / unpublish** | Flag change or schedule window elapses | Continuous (state baseline) |
| **Publish schedule** | `publish_up` / `publish_down` edited | On each change |
| **Campaign restart** | Contact re-enters with `allowRestart` + rotation > 1 | Once per campaign until cleared |
| **Scheduled email stall** | `email.send` scheduled count unchanged for N hours | Once per stall window |
| **Maintenance stall** | Maintenance lock files present too long | Configurable |

Messages are built by `MessageFormatter` for WeCom **markdown** (default) or plain **text**.

### Example (email send)

```
📧 **Campaign email started sending**
> Campaign: **Onboarding**
> Email: **Welcome**
```

### Example (stall)

```
⚠️ **Scheduled campaign emails stalled**
> Unchanged for: **2h 15m ago**
> Since: 2026-09-28 12:00 (local time)
```

---

## WeCom setup

1. In WeCom group chat → Group robots → Add robot → copy webhook URL.
2. URL shape:

```
https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=YOUR-KEY
```

3. Configure only **YOUR-KEY** in the plugin (Configuration → WeCom Campaign Alert).

---

## Install

```bash
# Remove old plugin if present
rm -rf plugins/CampaignEmailWebhookBundle plugins/WeComCampaignAlertBundle

# Copy this repo into Mautic plugins (directory name must be WeComCampaignAlertBundle)
cp -r plugin-wecom-campaign-alert /path/to/mautic/plugins/WeComCampaignAlertBundle

cd /path/to/mautic
php bin/console cache:clear
php bin/console mautic:plugins:reload
# Or: Settings → Plugins → Install/Upgrade
```

Enable the plugin under **Settings → Plugins**, then open **Configuration → WeCom Campaign Alert** and set the robot key.

---

## Configuration parameters

| Parameter | Default | Description |
|-----------|---------|-------------|
| `wecom_campaign_alert_enabled` | `false` | Master switch |
| `wecom_campaign_alert_key` | `''` | WeCom robot key only |
| `wecom_campaign_alert_msgtype` | `markdown` | `markdown` or `text` |
| `wecom_campaign_alert_email_send_enabled` | `true` | Email-send notices |
| `wecom_campaign_alert_email_open_enabled` | `true` | Email-open notices |
| `wecom_campaign_alert_publish_enabled` | `true` | Publish / unpublish |
| `wecom_campaign_alert_publish_schedule_enabled` | `true` | Schedule edits |
| `wecom_campaign_alert_restart_enabled` | `true` | Contact restart |
| `wecom_campaign_alert_stall_enabled` | `true` | Scheduled-email stall |
| `wecom_campaign_alert_stall_hours` | `1` | Hours without count change |
| `wecom_campaign_alert_maintenance_stall_enabled` | `true` | Maintenance lock stall |
| `wecom_campaign_alert_maintenance_window_start` | `6` | Asia/Shanghai hour (inclusive) |
| `wecom_campaign_alert_maintenance_window_end` | `17` | Asia/Shanghai hour (exclusive) |
| `wecom_campaign_alert_timeout` | `10` | HTTP timeout (seconds) |
| `wecom_campaign_alert_maintenance_locks` | see below | Paths; if any exist, cron commands skip |

Default lock paths:

- `/opt/mautic7/var/cache/run/.maintenance-cronjob`
- `/opt/mautic7/var/cache/run/.maintenance-schedule`

---

## Stall detection (important)

Command: `mautic:wecom:check-scheduled-emails`

State keys in `wecom_campaign_alert_state`:

| Key | Meaning |
|-----|---------|
| `scheduled_email_count` | Last observed `COUNT(*)` of scheduled `email.send` |
| `scheduled_email_count_since` | When that count was last observed / reset |
| `scheduled_email_stalled_alerted` | `1` if already alerted for this window |

**Logic (v1.0.3+):**

1. **Count is 0** (queue drained after sending finished) → **always** reset state to `count=0`, `alerted=0`. Never alert. This fixes false stall alerts when the counter was not refreshed after sends completed.
2. **Count changed** (progress or new backlog) → reset stall window (`since=now`, `alerted=0`).
3. **Count unchanged** and elapsed ≥ `stall_hours` and not yet alerted → send WeCom alert once.
4. Use `--force` to re-send within the same window.

---

## Console commands

| Command | Purpose |
|---------|---------|
| `mautic:wecom:check-scheduled-emails` | Stall detection |
| `mautic:wecom:check-campaign-publish` | Effective publish from `publish_up` / `publish_down` |
| `mautic:wecom:check-maintenance-locks` | Maintenance lock monitoring |
| `mautic:wecom:clear-notices` | Clear once-only markers (recurring campaigns) |
| `mautic:wecom:test-notice` | Send dummy messages for each event type |

Common options: `--force` (bypass plugin/maintenance gates), `--dry-run`.

### Test notices

```bash
php bin/console mautic:wecom:test-notice
php bin/console mautic:wecom:test-notice -t email_send -t stall
php bin/console mautic:wecom:test-notice --type=publish,unpublish,restart
php bin/console mautic:wecom:test-notice --dry-run
php bin/console mautic:wecom:test-notice --force
```

Types: `email_send`, `email_open`, `publish`, `unpublish`, `publish_schedule`, `restart`, `stall`, `all`.

### Clear notices (recurring campaigns)

```bash
php bin/console mautic:wecom:clear-notices --campaign-id=12
php bin/console mautic:wecom:clear-notices -c 12 -c 34
php bin/console mautic:wecom:clear-notices --all --type=email_send --type=email_open
php bin/console mautic:wecom:clear-notices --older-than=24h
```

On **contact restart** (`allowRestart` + rotation > 1), send/open markers for that campaign are cleared automatically.

---

## Suggested cron

```bash
# Every 15 minutes — stall detection
*/15 * * * * php /path/to/mautic/bin/console mautic:wecom:check-scheduled-emails

# Every 5 minutes — publish schedule transitions
*/5 * * * * php /path/to/mautic/bin/console mautic:wecom:check-campaign-publish

# Optional — maintenance locks
*/30 * * * * php /path/to/mautic/bin/console mautic:wecom:check-maintenance-locks
```

---

## Database tables

Created/upgraded on plugin install (`Migrations/Version_1_0_0.php`, `Version_1_0_1.php` + install event):

| Table | Purpose |
|-------|---------|
| `{prefix}wecom_campaign_alert_notices` | PK `(event_type, campaign_id, email_id)` once-only markers |
| `{prefix}wecom_campaign_alert_state` | Key/value stall & publish baselines |

MySQL/MariaDB and PostgreSQL supported. After deploy run **Install/Upgrade Plugins** (or `mautic:plugins:reload`).

---

## Development & tests

```bash
composer install
composer test
# or
vendor/bin/phpunit --configuration phpunit.xml.dist
```

CI (GitHub Actions) runs PHPUnit 11.5 on **PHP 8.2, 8.5, and 8.6**.

Unit coverage includes:

- `MessageFormatter` (all alert types, markdown/text, escaping, relative time)
- `CommandGuard` (enable/disable, force, lock files)
- Stall decision table (count=0 reset, progress reset, alert / skip / force)
- `WeComSender` msgtype / empty key (HTTP client optional in standalone env)

---

## Production checklist

- [x] Master switch + per-notice toggles
- [x] WeCom markdown & text payloads
- [x] Once-only send/open markers with clear/reset for recurring campaigns
- [x] Stall detection with explicit **count=0** state refresh (no false positives after send completes)
- [x] Publish flag + schedule cron
- [x] Maintenance lock gates on all cron commands
- [x] Migrations for notices + state tables
- [x] Test-notice command for ops verification
- [x] PHPUnit 11.5 suite + GitHub Actions matrix
- [x] Funding / sponsor metadata (`.github/FUNDING.yml`)

---

## Support / sponsor

If this plugin helps your stack:

- GitHub Sponsors: [wieslawgolec](https://github.com/sponsors/wieslawgolec)
- Buy Me a Coffee: [wieslawgolec](https://www.buymeacoffee.com/wieslawgolec)

---

## License

GPL-3.0-or-later — same family as Mautic core plugins.
