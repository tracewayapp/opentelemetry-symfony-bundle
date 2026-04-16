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

    #[DataProvider('operationSpanNameProvider')]
    public function testOperationSpanName(string $operation, ?string $dbName, string $expected): void
    {
        self::assertSame($expected, SqlOperationExtractor::operationSpanName($operation, $dbName));
    }

    /**
     * @return iterable<string, array{string, ?string, string}>
     */
    public static function operationSpanNameProvider(): iterable
    {
        yield 'with db name' => ['SELECT', 'my_db', 'SELECT my_db'];
        yield 'without db name' => ['INSERT', null, 'INSERT'];
        yield 'begin with db' => ['BEGIN', 'app', 'BEGIN app'];
        yield 'commit without db' => ['COMMIT', null, 'COMMIT'];
    }
}
