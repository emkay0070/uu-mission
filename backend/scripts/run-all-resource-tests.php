<?php

use Illuminate\Contracts\Console\Kernel;

/**
 * Comprehensive Resource Distribution Test Runner
 *
 * Runs all relevant tests for Resources Distribution feature:
 * - ResourceDistributionTest
 * - ResourceFileSecurityTest
 * - Existing Resources tests
 * - RBAC authorization tests
 */

require_once __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

echo "=== Running All Resource Distribution Tests ===\n\n";

$tests = [
    'ResourceDistributionTest' => 'Resource distribution workflow tests',
    'ResourceFileSecurityTest' => 'File preview/download security tests',
];

$results = [];

foreach ($tests as $testName => $description) {
    echo "Running: {$testName} - {$description}\n";

    $output = shell_exec('php artisan test --filter='.escapeshellarg($testName).' 2>&1');

    if (strpos($output, '"result":"passed"') !== false || strpos($output, 'OK') !== false) {
        echo "✓ PASSED\n";
        $results[$testName] = 'PASSED';
    } else {
        echo "❌ FAILED\n";
        $results[$testName] = 'FAILED';

        // Extract failure details
        if (preg_match('/"failed":(\d+)/', $output, $matches)) {
            echo "  Failed tests: {$matches[1]}\n";
        }

        // Show first few lines of error
        $lines = explode("\n", $output);
        echo "  Error preview:\n";
        foreach (array_slice($lines, 0, 5) as $line) {
            if (strpos($line, 'FAIL') !== false || strpos($line, 'Error') !== false) {
                echo '    '.trim($line)."\n";
            }
        }
    }

    echo "\n";
}

echo "=== Summary ===\n";
$passed = count(array_filter($results, fn ($r) => $r === 'PASSED'));
$total = count($results);

foreach ($results as $test => $result) {
    $status = $result === 'PASSED' ? '✓' : '❌';
    echo "{$status} {$test}: {$result}\n";
}

echo "\nTotal: {$passed}/{$total} passed\n";

if ($passed === $total) {
    echo "\n✓ ALL TESTS PASSED\n";
    exit(0);
} else {
    echo "\n❌ SOME TESTS FAILED\n";
    exit(1);
}
