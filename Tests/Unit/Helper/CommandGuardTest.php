<?php
declare(strict_types=1);
namespace MauticPlugin\WeComCampaignAlertBundle\Tests\Unit\Helper;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use MauticPlugin\WeComCampaignAlertBundle\Helper\CommandGuard;
use PHPUnit\Framework\TestCase;
final class CommandGuardTest extends TestCase
{
    public function testDisabledWithoutForceBlocks(): void
    {
        $params = new CoreParametersHelper(['wecom_campaign_alert_enabled' => false]);
        $guard  = new CommandGuard($params);
        $reason = $guard->getBlockReason(false);
        self::assertNotNull($reason);
        self::assertStringContainsString('disabled', $reason);
    }
    public function testForceBypassesDisabled(): void
    {
        $params = new CoreParametersHelper(['wecom_campaign_alert_enabled' => false]);
        $guard  = new CommandGuard($params);
        self::assertNull($guard->getBlockReason(true));
    }
    public function testEnabledAllowsRun(): void
    {
        $params = new CoreParametersHelper([
            'wecom_campaign_alert_enabled' => true,
            'wecom_campaign_alert_maintenance_locks' => ['/tmp/wecom-test-lock-does-not-exist-xyz'],
        ]);
        $guard = new CommandGuard($params);
        self::assertNull($guard->getBlockReason(false));
        self::assertTrue($guard->isPluginEnabled());
    }
    public function testStringTruthyEnabled(): void
    {
        $params = new CoreParametersHelper(['wecom_campaign_alert_enabled' => '1']);
        $guard  = new CommandGuard($params);
        self::assertTrue($guard->isPluginEnabled());
    }
    public function testLockfilePathsFromArray(): void
    {
        $paths  = ['/a/lock', '/b/lock'];
        $params = new CoreParametersHelper(['wecom_campaign_alert_maintenance_locks' => $paths]);
        $guard  = new CommandGuard($params);
        self::assertSame($paths, $guard->getLockfilePaths());
    }
    public function testLockfilePathsFromCsv(): void
    {
        $params = new CoreParametersHelper(['wecom_campaign_alert_maintenance_locks' => '/x,/y']);
        $guard  = new CommandGuard($params);
        self::assertSame(['/x', '/y'], $guard->getLockfilePaths());
    }
    public function testLockfilePathsFromNewlines(): void
    {
        $params = new CoreParametersHelper([
            'wecom_campaign_alert_maintenance_locks' => "/path/one\n/path/two\r\n/path/three",
        ]);
        $guard = new CommandGuard($params);
        self::assertSame(['/path/one', '/path/two', '/path/three'], $guard->getLockfilePaths());
    }
    public function testEmptyLocksDisableGating(): void
    {
        $params = new CoreParametersHelper([
            'wecom_campaign_alert_enabled' => true,
            'wecom_campaign_alert_maintenance_locks' => [],
        ]);
        $guard = new CommandGuard($params);
        self::assertSame([], $guard->getLockfilePaths());
        self::assertNull($guard->getBlockReason(false));
    }
    public function testNullLocksUseDefaults(): void
    {
        $params = new CoreParametersHelper(['wecom_campaign_alert_enabled' => true]);
        $guard  = new CommandGuard($params);
        $paths  = $guard->getLockfilePaths();
        self::assertContains('/opt/mautic7/var/cache/run/.maintenance-cronjob', $paths);
        self::assertContains('/opt/mautic7/var/cache/run/.maintenance-schedule', $paths);
    }
    public function testActiveLockBlocks(): void
    {
        $lock = sys_get_temp_dir().'/wecom_test_lock_'.uniqid('', true);
        file_put_contents($lock, '1');
        try {
            $params = new CoreParametersHelper([
                'wecom_campaign_alert_enabled' => true,
                'wecom_campaign_alert_maintenance_locks' => [$lock],
            ]);
            $guard = new CommandGuard($params);
            $reason = $guard->getBlockReason(false);
            self::assertNotNull($reason);
            self::assertStringContainsString('Maintenance lock', $reason);
            self::assertNull($guard->getBlockReason(true));
        } finally {
            @unlink($lock);
        }
    }
}
