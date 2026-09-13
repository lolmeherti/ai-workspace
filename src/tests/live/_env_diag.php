<?php
require '/var/www/html/vendor/autoload.php';
$path = '/var/www/html/.env';
echo "exists=" . (file_exists($path) ? 'yes' : 'no') . "\n";
echo "is_readable=" . (is_readable($path) ? 'yes' : 'no') . "\n";
echo "is_writable=" . (is_writable($path) ? 'yes' : 'no') . "\n";
$test = '/var/www/html/.env_diag_test';
$r = @file_put_contents($test, 'x');
echo "sibling_write=" . var_export($r, true) . "\n";
if ($r !== false) { @unlink($test); echo "sibling_cleanup=ok\n"; }
$lines = file($path, FILE_IGNORE_NEW_LINES);
foreach ($lines as $l) {
    foreach (['LLM_MODEL_ID','LLM_CTX_SIZE','LLM_SAMPLING','LLM_RUNTIME_POLICY'] as $k) {
        if (str_starts_with($l, $k . '=')) { echo $l . "\n"; }
    }
}
