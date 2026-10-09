#!/usr/bin/env php
<?php

/**
 * Informe agregado de shadow V3 desde logs Laravel (solo lectura).
 *
 * Formato esperado (Monolog / canal single|daily):
 *   [2026-10-08 12:00:00] staging.INFO: laboratory_preparation_v3_shadow_completed {"purchase_id":1,...}
 *
 * Uso:
 *   php scripts/laboratory-preparation-shadow-log-report.php
 *   php scripts/laboratory-preparation-shadow-log-report.php storage/logs/laravel-2026-10-08.log
 *   php scripts/laboratory-preparation-shadow-log-report.php storage/logs/laravel*.log
 *
 * No imprime indicaciones clínicas ni payloads con texto de preparación.
 */

declare(strict_types=1);

$paths = array_slice($argv, 1);
if ($paths === []) {
    $paths = [__DIR__.'/../storage/logs/laravel.log'];
    $daily = glob(__DIR__.'/../storage/logs/laravel-*.log') ?: [];
    $paths = array_values(array_unique([...$paths, ...$daily]));
}

$expanded = [];
foreach ($paths as $path) {
    foreach (glob($path) ?: [] as $match) {
        if (is_readable($match)) {
            $expanded[] = $match;
        }
    }
}

if ($expanded === []) {
    fwrite(STDERR, "No se encontraron archivos de log legibles.\n");
    exit(1);
}

/** @var list<string> */
const SHADOW_EVENTS = [
    'laboratory_preparation_v3_shadow_completed',
    'laboratory_preparation_v3_shadow_failed',
    'laboratory_preparation_v3_shadow_skipped_stale_source',
    'laboratory_preparation_v3_shadow_skipped_empty_order',
    'laboratory_preparation_v3_shadow_skipped_active_v3_precedence',
];

$linePattern = '/^\[[^\]]+\]\s+\S+\.(INFO|WARNING|ERROR|DEBUG):\s+('.implode('|', SHADOW_EVENTS).')\s+(\{.*\})\s*$/';

$countsByEvent = array_fill_keys(SHADOW_EVENTS, 0);
$decisionStatus = [];
$fallbackCategory = [];
$fallbackReason = [];
$rulesApplied = [];
$exceptions = [];
$uniqueOrders = [];
$durations = [];
$needsProviderReview = 0;
$linesParsed = 0;
$linesSkipped = 0;

foreach ($expanded as $file) {
    $handle = fopen($file, 'rb');
    if ($handle === false) {
        continue;
    }

    while (($line = fgets($handle)) !== false) {
        $line = rtrim($line, "\r\n");
        if (! str_contains($line, 'laboratory_preparation_v3_shadow_')) {
            continue;
        }

        if (! preg_match($linePattern, $line, $matches)) {
            $linesSkipped++;

            continue;
        }

        $event = $matches[2];
        $json = json_decode($matches[3], true);
        if (! is_array($json)) {
            $linesSkipped++;

            continue;
        }

        $linesParsed++;
        $countsByEvent[$event] = ($countsByEvent[$event] ?? 0) + 1;

        $purchaseId = isset($json['purchase_id']) ? (string) $json['purchase_id'] : '';
        $sourceHash = isset($json['source_hash']) ? (string) $json['source_hash'] : '';
        if ($purchaseId !== '') {
            $uniqueOrders[$purchaseId.'|'.$sourceHash] = true;
        }

        if ($event === 'laboratory_preparation_v3_shadow_completed') {
            $status = (string) ($json['decision_status'] ?? 'unknown');
            $decisionStatus[$status] = ($decisionStatus[$status] ?? 0) + 1;

            $category = (string) ($json['fallback_category'] ?? 'none');
            $fallbackCategory[$category] = ($fallbackCategory[$category] ?? 0) + 1;

            $reason = (string) ($json['fallback_reason'] ?? 'none');
            $fallbackReason[$reason] = ($fallbackReason[$reason] ?? 0) + 1;

            if (! empty($json['needs_provider_review'])) {
                $needsProviderReview++;
            }

            if (isset($json['duration_ms']) && is_numeric($json['duration_ms'])) {
                $durations[] = (int) $json['duration_ms'];
            }

            foreach ($json['rules_applied'] ?? [] as $rule) {
                if (is_string($rule) && $rule !== '') {
                    $rulesApplied[$rule] = ($rulesApplied[$rule] ?? 0) + 1;
                }
            }
        }

        if ($event === 'laboratory_preparation_v3_shadow_failed') {
            $class = (string) ($json['exception'] ?? 'unknown');
            $exceptions[$class] = ($exceptions[$class] ?? 0) + 1;

            if (isset($json['duration_ms']) && is_numeric($json['duration_ms'])) {
                $durations[] = (int) $json['duration_ms'];
            }
        }
    }

    fclose($handle);
}

sort($durations);
$durationCount = count($durations);
$p50 = $durationCount > 0 ? $durations[(int) floor(($durationCount - 1) * 0.5)] : null;
$p95 = $durationCount > 0 ? $durations[(int) floor(($durationCount - 1) * 0.95)] : null;

$completed = $countsByEvent['laboratory_preparation_v3_shadow_completed'] ?? 0;
$auto = $decisionStatus['AUTO_CONSOLIDATED'] ?? 0;
$fallback = $decisionStatus['FALLBACK_ORIGINAL'] ?? 0;

echo "=== Laboratory preparation V3 shadow (read-only) ===\n";
echo 'Log files: '.implode(', ', $expanded)."\n";
echo "Lines parsed: {$linesParsed}\n";
echo 'Lines skipped (formato no reconocido): '.$linesSkipped."\n\n";

echo "--- Event counts ---\n";
foreach (SHADOW_EVENTS as $event) {
    echo sprintf("  %-60s %d\n", $event, $countsByEvent[$event] ?? 0);
}

echo "\n--- Unique orders (purchase_id + source_hash) ---\n";
echo '  '.count($uniqueOrders)."\n";

echo "\n--- Decision status (completed events only) ---\n";
foreach ($decisionStatus as $status => $count) {
    echo sprintf("  %-30s %d\n", $status, $count);
}
if ($completed > 0) {
    echo sprintf(
        "  AUTO share: %.1f%% | FALLBACK share: %.1f%%\n",
        ($auto / $completed) * 100,
        ($fallback / $completed) * 100
    );
}

echo "\n--- Fallback category ---\n";
ksort($fallbackCategory);
foreach ($fallbackCategory as $key => $count) {
    echo sprintf("  %-40s %d\n", $key, $count);
}

echo "\n--- Fallback reason ---\n";
ksort($fallbackReason);
foreach ($fallbackReason as $key => $count) {
    echo sprintf("  %-40s %d\n", $key, $count);
}

echo "\n--- Rules applied (occurrences) ---\n";
ksort($rulesApplied);
foreach ($rulesApplied as $rule => $count) {
    echo sprintf("  %-10s %d\n", $rule, $count);
}

echo "\n--- needs_provider_review=true (completed) ---\n";
echo "  {$needsProviderReview}\n";

echo "\n--- Shadow failures by exception class ---\n";
if ($exceptions === []) {
    echo "  (none)\n";
} else {
    ksort($exceptions);
    foreach ($exceptions as $class => $count) {
        echo sprintf("  %-60s %d\n", $class, $count);
    }
}

echo "\n--- Duration ms (completed + failed with duration_ms) ---\n";
echo "  samples: {$durationCount}\n";
echo '  p50: '.($p50 !== null ? (string) $p50 : 'n/a')."\n";
echo '  p95: '.($p95 !== null ? (string) $p95 : 'n/a')."\n";

exit(0);
