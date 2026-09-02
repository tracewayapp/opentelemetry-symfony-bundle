<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Routing;

use PHPUnit\Framework\TestCase;
use Traceway\OpenTelemetryBundle\Routing\RouteTemplateCacheFile;

final class RouteTemplateCacheFileTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/otel-cache-file-test-'.uniqid('', true);
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        @unlink(RouteTemplateCacheFile::pathIn($this->dir));
        @rmdir($this->dir);
    }

    public function testRenderedFileLoadsBackIdentically(): void
    {
        $map = ['api_item_show' => '/api/items/{id}'];

        file_put_contents(RouteTemplateCacheFile::pathIn($this->dir), RouteTemplateCacheFile::render($map));

        self::assertSame($map, RouteTemplateCacheFile::load($this->dir));
    }

    public function testMissingFileLoadsAsNull(): void
    {
        self::assertNull(RouteTemplateCacheFile::load($this->dir));
    }

    public function testCorruptedFileLoadsAsNull(): void
    {
        file_put_contents(RouteTemplateCacheFile::pathIn($this->dir), '<?php return [');

        self::assertNull(RouteTemplateCacheFile::load($this->dir), 'a truncated dump must never break request handling');
    }

    public function testNonMapEntriesAreDropped(): void
    {
        file_put_contents(RouteTemplateCacheFile::pathIn($this->dir), '<?php return ["ok" => "/ok", 0 => "/nope", "bad" => 42];');

        self::assertSame(['ok' => '/ok'], RouteTemplateCacheFile::load($this->dir));
    }

    public function testPathInNormalizesTrailingSeparator(): void
    {
        self::assertSame(
            RouteTemplateCacheFile::pathIn('/var/cache'),
            RouteTemplateCacheFile::pathIn('/var/cache/'),
        );
    }
}
