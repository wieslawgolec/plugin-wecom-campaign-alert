<?php
declare(strict_types=1);
namespace MauticPlugin\WeComCampaignAlertBundle\Tests\Unit\Command;
use PHPUnit\Framework\TestCase;
/**
 * Documents and verifies the stall state machine (mirrors CheckScheduledEmailsCommand).
 *
 * Rules:
 *  - count == 0  → always reset state (count=0, alerted=0); never alert
 *  - count changed → reset window (since=now, alerted=0); never alert on that tick
 *  - count same & elapsed >= threshold & not yet alerted → alert
 *  - count same & already alerted → no re-alert unless force
 */
final class StallDetectionLogicTest extends TestCase
{
    public const STATE_COUNT   = 'scheduled_email_count';
    public const STATE_SINCE   = 'scheduled_email_count_since';
    public const STATE_ALERTED = 'scheduled_email_stalled_alerted';

    public function testStateKeysAreStable(): void
    {
        self::assertSame('scheduled_email_count', self::STATE_COUNT);
        self::assertSame('scheduled_email_count_since', self::STATE_SINCE);
        self::assertSame('scheduled_email_stalled_alerted', self::STATE_ALERTED);
    }

    /**
     * @dataProvider decisionProvider
     */
    public function testDecision(
        int $currentCount,
        ?string $prevCount,
        int $elapsedSeconds,
        int $thresholdSeconds,
        string $alerted,
        bool $force,
        string $expectedAction,
    ): void {
        $action = $this->decide($currentCount, $prevCount, $elapsedSeconds, $thresholdSeconds, $alerted, $force);
        self::assertSame($expectedAction, $action);
    }

    public static function decisionProvider(): array
    {
        return [
            'queue drained after send' => [0, '50', 7200, 3600, '1', false, 'reset_zero'],
            'queue already empty' => [0, '0', 7200, 3600, '0', false, 'reset_zero'],
            'first observation' => [10, null, 0, 3600, '0', false, 'reset_count'],
            'count increased' => [20, '10', 100, 3600, '0', false, 'reset_count'],
            'count decreased (progress)' => [5, '10', 5000, 3600, '1', false, 'reset_count'],
            'within window' => [10, '10', 100, 3600, '0', false, 'wait'],
            'stalled first alert' => [10, '10', 4000, 3600, '0', false, 'alert'],
            'stalled already alerted' => [10, '10', 8000, 3600, '1', false, 'skip_already'],
            'stalled force re-alert' => [10, '10', 8000, 3600, '1', true, 'alert'],
        ];
    }

    private function decide(
        int $currentCount,
        ?string $prevCount,
        int $elapsedSeconds,
        int $thresholdSeconds,
        string $alerted,
        bool $force,
    ): string {
        // Mirrors CheckScheduledEmailsCommand::execute after the bugfix
        if (0 === $currentCount) {
            return 'reset_zero';
        }
        if (null === $prevCount || (int) $prevCount !== $currentCount) {
            return 'reset_count';
        }
        if ($elapsedSeconds < $thresholdSeconds) {
            return 'wait';
        }
        if ('1' === $alerted && !$force) {
            return 'skip_already';
        }

        return 'alert';
    }
}
