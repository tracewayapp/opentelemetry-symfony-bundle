<?php

declare(strict_types=1);

// SDK env must be set before Composer's autoload runs the SDK's _autoload.php.
$env = [
    'OTEL_PHP_AUTOLOAD_ENABLED' => 'true',
    'OTEL_SERVICE_NAME' => 'e2e-app',
    'OTEL_TRACES_EXPORTER' => 'otlp',
    'OTEL_METRICS_EXPORTER' => 'none',
    'OTEL_LOGS_EXPORTER' => 'none',
    'OTEL_EXPORTER_OTLP_PROTOCOL' => 'http/json',
    'OTEL_EXPORTER_OTLP_ENDPOINT' => 'http://127.0.0.1:4318',
];
foreach ($env as $name => $value) {
    $_SERVER[$name] = $_ENV[$name] = $value;
    putenv($name.'='.$value);
}

require __DIR__.'/vendor/autoload.php';

use Symfony\Component\HttpFoundation\Request;
use Traceway\OpenTelemetryBundle\E2E\E2EKernel;

$kernel = new E2EKernel('prod', false);

$request = Request::create('http://localhost/hello/world');
$response = $kernel->handle($request);
$kernel->terminate($request, $response);

if (200 !== $response->getStatusCode()) {
    fwrite(STDERR, sprintf("Scenario request failed: HTTP %d\n%s\n", $response->getStatusCode(), $response->getContent()));
    exit(1);
}

fwrite(STDOUT, "Scenario OK: ".$response->getContent()."\n");
// Spans flush via the SDK's registered shutdown handler when the process exits.
