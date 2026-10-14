<?php
declare(strict_types=1);
namespace Symfony\Contracts\Translation;
/**
 * Minimal TranslatorInterface stub for standalone unit tests.
 * Loads plugin messages.ini so assertions match production English copy.
 */
interface TranslatorInterface
{
    /**
     * @param array<string, string> $parameters
     */
    public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string;
}
class TranslatorStub implements TranslatorInterface
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
        // ini may store escaped newlines as \\n
        $text = str_replace(['\\n', '\n'], "\n", $text);
        return strtr($text, $parameters);
    }
}
