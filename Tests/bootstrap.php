<?php
declare(strict_types=1);
$root = dirname(__DIR__);
if (is_file($root.'/vendor/autoload.php')) {
    require $root.'/vendor/autoload.php';
}
// Stubs for optional Mautic/Symfony classes used in pure unit tests
$stubDir = __DIR__.'/Stub';
if (is_dir($stubDir)) {
    foreach (glob($stubDir.'/*.php') ?: [] as $stubFile) {
        require_once $stubFile;
    }
}
spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'MauticPlugin\\WeComCampaignAlertBundle\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = $root.'/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
    if (is_file($file)) {
        require_once $file;
    }
});
