<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Doctrine\Middleware;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Traceway\OpenTelemetryBundle\Doctrine\Middleware\SqlOperationExtractor;

final class SqlOperationExtractorTest extends TestCase
{
    #[DataProvider('extractProvider')]
    public function testExtract(string $sql, string $expected): void
    {
        self::assertSame($expected, SqlOperationExtractor::extract($sql));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function extractProvider(): iterable
    {
        yield 'select' => ['SELECT * FROM users', 'SELECT'];
        yield 'insert' => ['INSERT INTO users (name) VALUES (?)', 'INSERT'];
        yield 'update' => ['UPDATE users SET name = ?', 'UPDATE'];
        yield 'delete' => ['DELETE FROM users WHERE id = ?', 'DELETE'];
        yield 'create table' => ['CREATE TABLE test (id INT)', 'CREATE'];
        yield 'drop table' => ['DROP TABLE IF EXISTS test', 'DROP'];
        yield 'begin' => ['BEGIN', 'BEGIN'];
        yield 'commit' => ['COMMIT', 'COMMIT'];
        yield 'rollback' => ['ROLLBACK', 'ROLLBACK'];
        yield 'lowercase' => ['select 1', 'SELECT'];
        yield 'leading whitespace' => ['  SELECT 1', 'SELECT'];
        yield 'leading tab' => ["\tINSERT INTO t VALUES (1)", 'INSERT'];
        yield 'empty string' => ['', 'UNKNOWN'];
        yield 'whitespace only' => ['   ', 'UNKNOWN'];
    }

    #[DataProvider('extractTargetProvider')]
    public function testExtractTarget(string $sql, ?string $expected): void
    {
        self::assertSame($expected, SqlOperationExtractor::extractTarget($sql));
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function extractTargetProvider(): iterable
    {
        // INSERT
        yield 'insert simple' => ['INSERT INTO items (name) VALUES (?)', 'items'];
        yield 'insert lowercase' => ['insert into items values (?)', 'items'];
        yield 'insert qualified' => ['INSERT INTO public.items (name) VALUES (?)', 'public.items'];
        yield 'insert backticked' => ['INSERT INTO `items` VALUES (?)', 'items'];
        yield 'insert double-quoted' => ['INSERT INTO "items" VALUES (?)', 'items'];
        yield 'insert sqlite OR REPLACE' => ['INSERT OR REPLACE INTO items VALUES (?)', 'items'];

        // UPDATE
        yield 'update simple' => ['UPDATE items SET price = ?', 'items'];
        yield 'update lowercase' => ['update items set price = ?', 'items'];
        yield 'update qualified' => ['UPDATE public.items SET price = ?', 'public.items'];
        yield 'update backticked' => ['UPDATE `items` SET price = ?', 'items'];
        yield 'update sqlite OR FAIL' => ['UPDATE OR FAIL items SET price = ?', 'items'];
        yield 'update with alias (no target)' => ['UPDATE items i SET price = ?', null];

        // DELETE
        yield 'delete simple' => ['DELETE FROM items WHERE id = ?', 'items'];
        yield 'delete lowercase' => ['delete from items', 'items'];
        yield 'delete qualified' => ['DELETE FROM public.items', 'public.items'];
        yield 'delete bracketed' => ['DELETE FROM [items] WHERE id = 1', 'items'];

        // SELECT
        yield 'select star' => ['SELECT * FROM items', 'items'];
        yield 'select with where' => ['SELECT id FROM items WHERE name = ?', 'items'];
        yield 'select with join' => ['SELECT * FROM items JOIN orders ON items.id = orders.item_id', 'items'];
        yield 'select with alias' => ['SELECT u.name FROM users u WHERE u.id = ?', 'users'];
        yield 'select count' => ['SELECT COUNT(*) FROM items', 'items'];
        yield 'select multiline' => ["SELECT *\n  FROM items\n  WHERE id = ?", 'items'];
        yield 'select qualified' => ['SELECT * FROM public.items', 'public.items'];

        // REPLACE (MySQL/SQLite)
        yield 'replace into' => ['REPLACE INTO items VALUES (?)', 'items'];

        // No target / unsupported
        yield 'begin' => ['BEGIN', null];
        yield 'commit' => ['COMMIT', null];
        yield 'rollback' => ['ROLLBACK', null];
        yield 'create table' => ['CREATE TABLE items (id INT)', null];
        // Subquery in FROM: regex backtracks past the outer `(` to find the
        // first FROM followed by a valid identifier, which is the inner table.
        // Acceptable for span-naming since cardinality is still bounded.
        yield 'select subquery captures inner table' => ['SELECT * FROM (SELECT id FROM users) sub', 'users'];
        yield 'empty' => ['', null];
        yield 'unknown' => ['EXPLAIN ANALYZE SELECT 1', null];
    }

    #[DataProvider('spanNameProvider')]
    public function testSpanName(string $operation, ?string $target, ?string $dbName, string $expected): void
    {
        self::assertSame($expected, SqlOperationExtractor::spanName($operation, $target, $dbName));
    }

    /**
     * @return iterable<string, array{string, ?string, ?string, string}>
     */
    public static function spanNameProvider(): iterable
    {
        yield 'target wins over db' => ['SELECT', 'items', 'my_db', 'SELECT items'];
        yield 'target only' => ['INSERT', 'items', null, 'INSERT items'];
        yield 'db only' => ['SELECT', null, 'my_db', 'SELECT my_db'];
        yield 'neither' => ['BEGIN', null, null, 'BEGIN'];
    }
}
