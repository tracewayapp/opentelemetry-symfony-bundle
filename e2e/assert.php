<?php

declare(strict_types=1);

// Asserts against what the collector actually received — the real E2E contract.

$file = __DIR__.'/output/traces.jsonl';
if (!is_file($file) || '' === trim((string) file_get_contents($file))) {
    fwrite(STDERR, "FAIL: collector output file missing or empty: $file\n");
    exit(1);
}

/** @var list<array{name: string, kind: int|string, traceId: string, attributes: array<string, mixed>, resource: array<string, mixed>}> $spans */
$spans = [];

foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $doc = json_decode($line, true);
    if (!is_array($doc)) {
        continue;
    }
    foreach ($doc['resourceSpans'] ?? [] as $resourceSpan) {
        $resource = attrs($resourceSpan['resource']['attributes'] ?? []);
        foreach ($resourceSpan['scopeSpans'] ?? [] as $scopeSpan) {
            foreach ($scopeSpan['spans'] ?? [] as $span) {
                $spans[] = [
                    'name' => $span['name'] ?? '',
                    'kind' => $span['kind'] ?? 0,
                    'traceId' => $span['traceId'] ?? '',
                    'attributes' => attrs($span['attributes'] ?? []),
                    'resource' => $resource,
                ];
            }
        }
    }
}

/** @param list<array{key: string, value: array<string, mixed>}> $raw */
function attrs(array $raw): array
{
    $out = [];
    foreach ($raw as $attr) {
        $value = $attr['value'] ?? [];
        $out[$attr['key']] = $value['stringValue']
            ?? (isset($value['intValue']) ? (int) $value['intValue'] : null)
            ?? $value['boolValue']
            ?? $value['doubleValue']
            ?? null;
    }

    return $out;
}

function findSpan(array $spans, string $name): ?array
{
    foreach ($spans as $span) {
        if ($span['name'] === $name) {
            return $span;
        }
    }

    return null;
}

function isServerKind(int|string $kind): bool
{
    return 2 === $kind || 'SPAN_KIND_SERVER' === $kind;
}

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if ($ok) {
        fwrite(STDOUT, "  ok  $message\n");
    } else {
        $failures[] = $message;
        fwrite(STDOUT, "FAIL  $message\n");
    }
};

fwrite(STDOUT, sprintf("Collector received %d span(s): %s\n", count($spans), implode(', ', array_column($spans, 'name'))));

$server = findSpan($spans, 'GET /hello/{name}');
$check(null !== $server, 'SERVER span "GET /hello/{name}" arrived at the collector');

if (null !== $server) {
    $check(isServerKind($server['kind']), 'server span has SpanKind SERVER');
    $check('/hello/{name}' === ($server['attributes']['http.route'] ?? null), 'http.route is the route template');
    $check(200 === ($server['attributes']['http.response.status_code'] ?? null), 'http.response.status_code is 200');
    $check('GET' === ($server['attributes']['http.request.method'] ?? null), 'http.request.method is GET');
    $check('127.0.0.1' === ($server['attributes']['network.peer.address'] ?? null), 'network.peer.address recorded');
    $check('e2e-app' === ($server['resource']['service.name'] ?? null), 'resource service.name is e2e-app');
}

$work = findSpan($spans, 'e2e.work');
$check(null !== $work, 'manual TracingInterface span "e2e.work" arrived');

$cacheSpan = findSpan($spans, 'cache.get');
$check(null !== $cacheSpan, 'cache.get span arrived');
if (null !== $cacheSpan) {
    $check(false === ($cacheSpan['attributes']['cache.hit'] ?? null), 'cache.get records the miss');
}

if (null !== $server && null !== $work && null !== $cacheSpan) {
    $check($server['traceId'] === $work['traceId'] && $server['traceId'] === $cacheSpan['traceId'], 'all spans share one trace');
}

if ([] !== $failures) {
    fwrite(STDERR, sprintf("\nE2E FAILED: %d assertion(s) failed.\n", count($failures)));
    exit(1);
}

fwrite(STDOUT, "\nE2E PASSED.\n");
