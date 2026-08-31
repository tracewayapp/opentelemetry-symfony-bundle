<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Routing;

use Symfony\Component\Config\ConfigCache;

/**
 * Location and format of the dumped `route name => path template` map, written by
 * {@see RouteTemplateCacheWarmer} and read by {@see RouteTemplateResolver}.
 *
 * A plain `<?php return [...];` array, so opcache serves it from shared memory.
 */
final class RouteTemplateCacheFile
{
    public const FILE_NAME = 'traceway_otel_route_templates.php';

    public static function pathIn(string $dir): string
    {
        return rtrim($dir, '/\\').\DIRECTORY_SEPARATOR.self::FILE_NAME;
    }

    /**
     * @param array<string, string> $routeTemplates
     */
    public static function render(array $routeTemplates): string
    {
        return \sprintf("<?php\n\nreturn %s;\n", var_export($routeTemplates, true));
    }

    /**
     * Checks the dump against the routing resources in its `.meta` file, which
     * costs a stat per resource — worth it only in debug. A dump with no `.meta`
     * counts as stale: an unverifiable map is not to be trusted.
     */
    public static function isFreshIn(string $dir): bool
    {
        try {
            return (new ConfigCache(self::pathIn($dir), true))->isFresh();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, string>|null null when the dump is missing or unusable
     */
    public static function load(string $dir): ?array
    {
        $file = self::pathIn($dir);

        if (!is_file($file)) {
            return null;
        }

        try {
            $routeTemplates = require $file;
        } catch (\Throwable) {
            // A truncated dump must never break request handling.
            return null;
        }

        if (!\is_array($routeTemplates)) {
            return null;
        }

        $map = [];
        foreach ($routeTemplates as $name => $template) {
            if (\is_string($name) && \is_string($template)) {
                $map[$name] = $template;
            }
        }

        return $map;
    }
}
