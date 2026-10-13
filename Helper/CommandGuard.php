<?php

declare(strict_types=1);

namespace MauticPlugin\WeComCampaignAlertBundle\Helper;

use Mautic\CoreBundle\Helper\CoreParametersHelper;

/**
 * Shared run gates for plugin console commands.
 *
 * Commands run only when:
 *  - plugin is enabled, OR --force is passed
 *  - AND no maintenance lock file exists (unless --force or $ignoreMaintenanceLocks)
 */
class CommandGuard
{
    /** @var list<string> */
    private const DEFAULT_LOCKFILES = [
        '/opt/mautic7/var/cache/run/.maintenance-cronjob',
        '/opt/mautic7/var/cache/run/.maintenance-schedule',
    ];

    public function __construct(private readonly CoreParametersHelper $coreParametersHelper)
    {
    }

    /**
     * @return list<string> Existing lock file paths
     */
    public function getActiveMaintenanceLocks(): array
    {
        $paths  = $this->getLockfilePaths();
        $active = [];
        foreach ($paths as $path) {
            if (is_string($path) && '' !== $path && file_exists($path)) {
                $active[] = $path;
            }
        }

        return $active;
    }

    public function isPluginEnabled(): bool
    {
        $raw = $this->coreParametersHelper->get('wecom_campaign_alert_enabled', false);

        return $this->toBool($raw);
    }

    /**
     * @return string|null Blocking reason, or null if command may run
     */
    public function getBlockReason(bool $force, bool $ignoreMaintenanceLocks = false): ?string
    {
        if (!$force && !$this->isPluginEnabled()) {
            $raw = $this->coreParametersHelper->get('wecom_campaign_alert_enabled', null);

            return sprintf(
                'WeCom Campaign Alert is disabled (wecom_campaign_alert_enabled=%s). Enable in Configuration UI, save, then cache:clear — or pass --force.',
                var_export($raw, true)
            );
        }

        if (!$force && !$ignoreMaintenanceLocks) {
            $locks = $this->getActiveMaintenanceLocks();
            if ([] !== $locks) {
                return 'Maintenance lock present: '.implode(', ', $locks);
            }
        }

        return null;
    }

    /**
     * Resolve configured lock paths.
     *
     * - null / unset → built-in defaults
     * - empty array or blank string → no locks (UI cleared the field)
     * - array or newline/comma-separated string → those paths
     *
     * @return list<string>
     */
    public function getLockfilePaths(): array
    {
        $configured = $this->coreParametersHelper->get('wecom_campaign_alert_maintenance_locks', null);

        if (null === $configured) {
            return self::DEFAULT_LOCKFILES;
        }

        if (is_array($configured)) {
            return array_values(array_filter(
                array_map(static fn ($p): string => trim((string) $p), $configured),
                static fn (string $p): bool => '' !== $p
            ));
        }

        if (is_string($configured)) {
            if ('' === trim($configured)) {
                return [];
            }

            $parts = preg_split('/[\r\n,]+/', $configured) ?: [];

            return array_values(array_filter(array_map('trim', $parts), static fn (string $p): bool => '' !== $p));
        }

        return self::DEFAULT_LOCKFILES;
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
