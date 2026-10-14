<?php

declare(strict_types=1);

return [
    'name'        => 'WeCom Campaign Alert',
    'description' => 'Sends WeCom (企业微信) group-robot markdown alerts once per campaign email send, and when scheduled email counts stall.',
    'version'     => '1.0.5',
    'author'      => 'Wieslaw Golec',

    'parameters' => [
        'wecom_campaign_alert_enabled'       => false,
        // WeCom group robot key only (URL is fixed)
        'wecom_campaign_alert_key'           => '',
        // text | markdown — default markdown
        'wecom_campaign_alert_msgtype'       => 'markdown',

        // Per-notice toggles (master switch wecom_campaign_alert_enabled must also be on)
        'wecom_campaign_alert_email_send_enabled'        => true,
        'wecom_campaign_alert_email_open_enabled'        => true,
        'wecom_campaign_alert_publish_enabled'           => true,
        'wecom_campaign_alert_publish_schedule_enabled'  => true,
        'wecom_campaign_alert_restart_enabled'           => true,
        'wecom_campaign_alert_stall_enabled'             => true,
        'wecom_campaign_alert_maintenance_stall_enabled'  => true,
        // Hour window in configured display timezone (0-23). Start inclusive, end exclusive.
        'wecom_campaign_alert_maintenance_window_start'   => 6,
        'wecom_campaign_alert_maintenance_window_end'     => 17,

        'wecom_campaign_alert_stall_hours'   => 1,
        'wecom_campaign_alert_timeout'       => 10,
        // IANA timezone for alert timestamps (default Asia/Shanghai)
        'wecom_campaign_alert_timezone'      => 'Asia/Shanghai',
        // Absolute paths (array, or newline/comma-separated string from UI).
        // null → built-in defaults; empty → no lock gating.
        'wecom_campaign_alert_maintenance_locks' => [
            '/opt/mautic7/var/cache/run/.maintenance-cronjob',
            '/opt/mautic7/var/cache/run/.maintenance-schedule',
        ],
    ],
];
