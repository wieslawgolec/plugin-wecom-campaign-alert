<?php
declare(strict_types=1);

namespace Symfony\Contracts\Translation;

if (!interface_exists(TranslatorInterface::class, false)) {
    /**
     * @param array<string, string> $parameters
     */
    interface TranslatorInterface
    {
        public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string;
    }
}

namespace MauticPlugin\WeComCampaignAlertBundle\Tests\Stub;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Loads plugin messages.ini so unit tests assert the same English copy as production.
 */
final class TranslatorStub implements TranslatorInterface
{
    /** @var array<string, string> */
    private array $catalogue = [];

    public function __construct(?string $messagesIniPath = null)
    {
        $path = $messagesIniPath ?? dirname(__DIR__, 2).'/Translations/en_US/messages.ini';
        if (is_file($path)) {
            $parsed = parse_ini_file($path, false, INI_SCANNER_RAW);
            if (is_array($parsed)) {
                $this->catalogue = $parsed;
            }
        }
    }

    public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        $text = $this->catalogue[$id] ?? $id;
        // RAW ini keeps \n as two chars; normalize to real newlines
        $text = str_replace(['\\n', "\n"], "\n", $text);

        return strtr($text, $parameters);
    }
}
