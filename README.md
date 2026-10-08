# WeCom Campaign Alert

Mautic 7 plugin for **WeCom (企业微信)** group-robot alerts.

## Features

1. **One alert per email per campaign** when campaign `email.send` runs.
2. **Stall alert** if scheduled `email.send` count does not change for N hours (default 1).

Messages are built by `MessageFormatter` for WeCom **markdown** (default) or plain text.

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

## WeCom setup

Robot webhook:

```
https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=YOUR-KEY
```

Configure only **YOUR-KEY** in the plugin.

## Install

```bash
# Remove old plugin if present
rm -rf plugins/CampaignEmailWebhookBundle

cp -r WeComCampaignAlertBundle /path/to/mautic/plugins/
cd /path/to/mautic
php bin/console cache:clear
php bin/console mautic:plugins:reload
```

Enable under **Settings → Plugins**.

## Config parameters

| Parameter | Default | Description |
|-----------|---------|-------------|
| `wecom_campaign_alert_enabled` | `false` | Email-send alerts |
| `wecom_campaign_alert_key` | `''` | Robot key |
| `wecom_campaign_alert_msgtype` | `markdown` | `markdown` or `text` |
| `wecom_campaign_alert_stall_enabled` | `true` | Stall detection |
| `wecom_campaign_alert_stall_hours` | `1` | Hours without count change |
| `wecom_campaign_alert_timeout` | `10` | HTTP timeout |


## Test notices

Send dummy messages for each event type (does **not** write to notice tracker):

```bash
# All types
php bin/console mautic:wecom:test-notice

# One or more types
php bin/console mautic:wecom:test-notice -t email_send -t stall
php bin/console mautic:wecom:test-notice --type=publish,unpublish,restart

# Preview only
php bin/console mautic:wecom:test-notice --dry-run

# Even if plugin disabled
php bin/console mautic:wecom:test-notice --force
```

Types: `email_send`, `email_open`, `publish`, `unpublish`, `publish_schedule`, `restart`, `stall`, `all`.


## Maintenance locks

All `mautic:wecom:*` commands skip when either lock file exists (unless `--force`):

- `/opt/mautic7/var/cache/run/.maintenance-cronjob`
- `/opt/mautic7/var/cache/run/.maintenance-schedule`

Configurable via `wecom_campaign_alert_maintenance_locks`. Plugin must also be enabled (or pass `--force`).


## Recurring campaigns / reset notices

Once-only send/open markers are stored in `wecom_campaign_alert_notices`. After a daily campaign reset (and email_stats purge), clear them so the next cycle can alert again:

```bash
# One campaign (run after your daily reset)
php bin/console mautic:wecom:clear-notices --campaign-id=12

# Several campaigns
php bin/console mautic:wecom:clear-notices -c 12 -c 34

# Only send/open markers
php bin/console mautic:wecom:clear-notices --all --type=email_send --type=email_open

# Anything older than 24 hours
php bin/console mautic:wecom:clear-notices --older-than=24h
```

On **contact restart** (`allowRestart` + rotation > 1), send/open markers for that campaign are cleared automatically so the new rotation can alert again.

## Cron

```bash
# Stall detection (scheduled email.send count unchanged)
php bin/console mautic:wecom:check-scheduled-emails

# Time-based publish/unpublish (publish_up / publish_down elapsed)
php bin/console mautic:wecom:check-campaign-publish
```

Options: `--dry-run` (both), `--force` (stall only).

## Tables

Created/upgraded on plugin install (`Migrations/Version_1_0_0.php`, `Version_1_0_1.php` + install event):

- `{prefix}wecom_campaign_alert_notices` — PK `(event_type, campaign_id, email_id)`
- `{prefix}wecom_campaign_alert_state`

MySQL/MariaDB and PostgreSQL supported. After deploy run **Install/Upgrade Plugins** (or `mautic:plugins:reload`).
