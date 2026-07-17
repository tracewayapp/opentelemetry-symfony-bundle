#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"

mkdir -p output var
rm -f output/traces.jsonl
chmod 777 output || true

docker compose up -d
trap 'docker compose down -v >/dev/null 2>&1 || true' EXIT

echo "Waiting for collector on :4318 ..."
for _ in $(seq 1 30); do
    if curl -s -o /dev/null http://127.0.0.1:4318; then
        break
    fi
    sleep 1
done

if [ ! -d vendor ]; then
    composer install --no-interaction --quiet
fi

rm -rf var/cache
php scenario.php

echo "Waiting for spans to land in the collector output ..."
for _ in $(seq 1 30); do
    if [ -s output/traces.jsonl ]; then
        break
    fi
    sleep 1
done

php assert.php
