<?php

declare(strict_types=1);

namespace MauticPlugin\WeComCampaignAlertBundle\Helper;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Posts to WeCom (企业微信) group robot webhook.
 *
 * URL: https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key={key}
 */
class WeComSender
{
    private const SEND_URL = 'https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=%s';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CoreParametersHelper $coreParametersHelper,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getMsgType(): string
    {
        $type = (string) $this->coreParametersHelper->get('wecom_campaign_alert_msgtype', 'markdown');

        return in_array($type, ['text', 'markdown'], true) ? $type : 'markdown';
    }

    /**
     * @param array<string, mixed> $context For logging only
     */
    public function send(string $content, array $context = []): bool
    {
        $key = trim((string) $this->coreParametersHelper->get('wecom_campaign_alert_key', ''));
        if ('' === $key) {
            $this->logger->warning('WeComCampaignAlert: robot key is empty, skipping send.');

            return false;
        }

        $url     = sprintf(self::SEND_URL, $key);
        $timeout = (int) $this->coreParametersHelper->get('wecom_campaign_alert_timeout', 10);
        $msgType = $this->getMsgType();

        if ('markdown' === $msgType) {
            $body = [
                'msgtype'  => 'markdown',
                'markdown' => ['content' => $content],
            ];
        } else {
            $body = [
                'msgtype' => 'text',
                'text'    => ['content' => $content],
            ];
        }

        try {
            $response = $this->httpClient->request('POST', $url, [
                'json'         => $body,
                'timeout'      => $timeout,
                'max_duration' => $timeout,
                'headers'      => ['Content-Type' => 'application/json'],
            ]);

            $status  = $response->getStatusCode();
            $raw     = $response->getContent(false);
            $decoded = json_decode($raw, true);
            $errcode = is_array($decoded) ? (int) ($decoded['errcode'] ?? -1) : -1;

            if ($status < 200 || $status >= 300 || 0 !== $errcode) {
                $this->logger->error(sprintf(
                    'WeComCampaignAlert: WeCom error HTTP %d errcode=%s errmsg=%s context=%s',
                    $status,
                    is_array($decoded) ? ($decoded['errcode'] ?? '?') : '?',
                    is_array($decoded) ? ($decoded['errmsg'] ?? $raw) : $raw,
                    json_encode($context)
                ));

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                'WeComCampaignAlert: send failed: %s context=%s',
                $e->getMessage(),
                json_encode($context)
            ));

            return false;
        }
    }
}
