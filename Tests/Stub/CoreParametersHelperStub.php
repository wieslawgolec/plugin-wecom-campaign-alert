<?php
declare(strict_types=1);
namespace Mautic\CoreBundle\Helper;
/**
 * Minimal stub so unit tests run without installing Mautic.
 */
class CoreParametersHelper
{
    /** @param array<string, mixed> $params */
    public function __construct(private array $params = [])
    {
    }
    public function get(string $name, mixed $default = null): mixed
    {
        return array_key_exists($name, $this->params) ? $this->params[$name] : $default;
    }
    public function set(string $name, mixed $value): void
    {
        $this->params[$name] = $value;
    }
}
