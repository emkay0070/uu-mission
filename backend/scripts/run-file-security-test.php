<?php

/**
 * Focused ResourceFileSecurityTest Runner
 */
$output = shell_exec('php artisan test --filter=ResourceFileSecurityTest 2>&1');
echo $output;

if (strpos($output, '"result":"passed"') !== false || strpos($output, 'OK') !== false) {
    echo "\n✓ PASSED\n";
    exit(0);
} else {
    echo "\n❌ FAILED\n";
    exit(1);
}
