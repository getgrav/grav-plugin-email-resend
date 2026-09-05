<?php

declare(strict_types=1);

/**
 * The plugin ships its own vendor directory, and that directory holds the
 * Symfony Resend bridge and Composer's autoloader and nothing else - Symfony
 * Mailer itself comes from Grav at runtime, which is why the plugin's
 * composer.json replaces it rather than requiring it.
 *
 * Installing PHPUnit into that same directory would put development packages
 * into the released plugin, so the suite keeps its own composer.json and its own
 * vendor directory here under tests/ instead. Run `composer install` in this
 * directory once, then `phpunit` from the repository root.
 */
$autoload = __DIR__ . '/vendor/autoload.php';

if (!is_file($autoload)) {
    fwrite(STDERR, "The test dependencies are not installed. Run: composer install -d tests\n");
    exit(1);
}

require $autoload;

/**
 * The provider contract lives in the Email plugin, which is a sibling checkout
 * rather than a Composer package - Grav plugins are installed by GPM, not by
 * Composer, so there is no package to require here.
 *
 * Only `Grav\Plugin\Email\Providers\*` is mapped, and by hand rather than
 * through the Email plugin's own autoloader: loading that would pull its
 * Symfony Mailer in beside the one this plugin ships against, and two copies of
 * Symfony Mailer in one process is a confusing afternoon.
 *
 * `EMAIL_PLUGIN_ROOT` names the checkout. With nothing set, the sibling folder
 * is tried first - which is where it sits in a Grav site and in a plain clone -
 * and then the sibling of the parent, which is where it sits when this plugin
 * is being worked on in a git worktree.
 */
$emailRoots = array_filter([
    getenv('EMAIL_PLUGIN_ROOT') ?: null,
    \dirname(__DIR__, 2) . '/grav-plugin-email',
    \dirname(__DIR__, 3) . '/grav-plugin-email',
    \dirname(__DIR__, 2) . '/email',
]);

$emailRoot = null;
foreach ($emailRoots as $candidate) {
    if (is_dir(rtrim((string)$candidate, '/') . '/classes/Providers')) {
        $emailRoot = rtrim((string)$candidate, '/');
        break;
    }
}

if ($emailRoot === null) {
    fwrite(STDERR, "The Email plugin was not found. Set EMAIL_PLUGIN_ROOT to a checkout of grav-plugin-email\n");
    exit(1);
}

spl_autoload_register(static function (string $class) use ($emailRoot): void {
    $prefix = 'Grav\\Plugin\\Email\\Providers\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $file = $emailRoot . '/classes/Providers/' . str_replace('\\', '/', substr($class, \strlen($prefix))) . '.php';

    if (is_file($file)) {
        require $file;
    }
});
