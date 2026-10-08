<?php
declare(strict_types=1);
namespace MauticPlugin\WeComCampaignAlertBundle\Tests\Unit\Helper;
use MauticPlugin\WeComCampaignAlertBundle\Helper\MessageFormatter;
use PHPUnit\Framework\TestCase;
final class MessageFormatterTest extends TestCase
{
    private MessageFormatter $fmt;
    protected function setUp(): void
    {
        $this->fmt = new MessageFormatter();
    }
    public function testEmailSendMarkdown(): void
    {
        $out = $this->fmt->formatEmailSendAlert([
            'campaign_name' => 'Onboarding',
            'email_name'    => 'Welcome',
        ], 'markdown');
        self::assertStringContainsString('Campaign email started sending', $out);
        self::assertStringContainsString('**Onboarding**', $out);
        self::assertStringContainsString('**Welcome**', $out);
    }
    public function testEmailSendText(): void
    {
        $out = $this->fmt->formatEmailSendAlert([
            'campaign_name' => 'C',
            'email_name'    => 'E',
        ], 'text');
        self::assertStringContainsString('Campaign: C', $out);
        self::assertStringNotContainsString('**', $out);
    }
    public function testEmailOpenMarkdown(): void
    {
        $out = $this->fmt->formatEmailOpenAlert([
            'campaign_name' => 'Camp',
            'email_name'    => 'Mail',
        ]);
        self::assertStringContainsString('opened', $out);
        self::assertStringContainsString('**Camp**', $out);
    }
    public function testPublishAndUnpublish(): void
    {
        $pub = $this->fmt->formatPublishAlert(['campaign_name' => 'X'], true, 'markdown', 'flag');
        self::assertStringContainsString('published', $pub);
        self::assertStringContainsString('🟢', $pub);
        $un = $this->fmt->formatPublishAlert(['campaign_name' => 'X'], false, 'markdown', 'schedule');
        self::assertStringContainsString('unpublished', $un);
        self::assertStringContainsString('(schedule)', $un);
    }
    public function testPublishSchedule(): void
    {
        $out = $this->fmt->formatPublishScheduleAlert([
            'campaign_name' => 'Sched',
            'publish_up'    => '2026-01-01T00:00:00+00:00',
            'publish_down'  => '2026-12-31T23:59:59+00:00',
        ]);
        self::assertStringContainsString('Publish schedule updated', $out);
        self::assertStringContainsString('Sched', $out);
        self::assertStringContainsString('local time', $out);
    }
    public function testRestart(): void
    {
        $out = $this->fmt->formatRestartAlert(['campaign_name' => 'R']);
        self::assertStringContainsString('restarting', $out);
        self::assertStringContainsString('**R**', $out);
    }
    public function testStallAlert(): void
    {
        $since = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-2 hours -15 minutes');
        $out   = $this->fmt->formatStallAlert(['since' => $since->format(\DateTimeInterface::ATOM)]);
        self::assertStringContainsString('stalled', $out);
        self::assertStringContainsString('2h 15m ago', $out);
    }
    public function testStallAlertUnknownSince(): void
    {
        $out = $this->fmt->formatStallAlert([]);
        self::assertStringContainsString('unknown duration', $out);
    }
    public function testMaintenanceLockStall(): void
    {
        $since = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-30 minutes');
        $out   = $this->fmt->formatMaintenanceLockStallAlert(['since' => $since]);
        self::assertStringContainsString('Maintenance mode stalled', $out);
        self::assertStringContainsString('30m ago', $out);
    }
    public function testFormatAgo(): void
    {
        $now   = new \DateTimeImmutable('2026-01-01 12:00:00', new \DateTimeZone('UTC'));
        $since = $now->modify('-1 hour');
        self::assertSame('1h ago', $this->fmt->formatAgo($since, $now));
        self::assertSame('just now', $this->fmt->formatAgo($now, $now));
    }
    public function testEscapesMarkdownSpecialChars(): void
    {
        $out = $this->fmt->formatEmailSendAlert([
            'campaign_name' => 'A*B#C',
            'email_name'    => '<tag>',
        ], 'markdown');
        self::assertStringNotContainsString('*B', $out);
        self::assertStringNotContainsString('<tag>', $out);
    }
}
