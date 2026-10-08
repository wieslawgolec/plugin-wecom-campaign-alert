<?php
declare(strict_types=1);
namespace MauticPlugin\WeComCampaignAlertBundle\Tests\Unit\Helper;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use MauticPlugin\WeComCampaignAlertBundle\Helper\WeComSender;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;
final class WeComSenderTest extends TestCase
{
    public function testEmptyKeyReturnsFalse(): void
    {
        if (!interface_exists(HttpClientInterface::class)) {
            self::markTestSkipped('symfony/http-client not installed in standalone test env');
        }
        $http = $this->createMock(HttpClientInterface::class);
        $http->expects(self::never())->method('request');
        $sender = new WeComSender(
            $http,
            new CoreParametersHelper(['wecom_campaign_alert_key' => '']),
            new NullLogger()
        );
        self::assertFalse($sender->send('hello'));
    }
    public function testMsgTypeDefaultMarkdown(): void
    {
        if (!interface_exists(HttpClientInterface::class)) {
            self::markTestSkipped('symfony/http-client not installed');
        }
        $sender = new WeComSender(
            $this->createMock(HttpClientInterface::class),
            new CoreParametersHelper([]),
            new NullLogger()
        );
        self::assertSame('markdown', $sender->getMsgType());
    }
    public function testMsgTypeText(): void
    {
        if (!interface_exists(HttpClientInterface::class)) {
            self::markTestSkipped('symfony/http-client not installed');
        }
        $sender = new WeComSender(
            $this->createMock(HttpClientInterface::class),
            new CoreParametersHelper(['wecom_campaign_alert_msgtype' => 'text']),
            new NullLogger()
        );
        self::assertSame('text', $sender->getMsgType());
    }
}
