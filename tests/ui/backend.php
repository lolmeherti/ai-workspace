<?php
// Targeted deterministic domain suites; no configuration or live services loaded.
spl_autoload_register(function (string $class): void {
    if (!str_starts_with($class, 'App\\')) return;
    $file = dirname(__DIR__, 2) . '/src/' . str_replace('\\', '/', $class) . '.php';
    if (is_file($file)) require_once $file;
});
$ok = true;
foreach (['RateReplyActionTest', 'JobStateMachineTest'] as $suite) {
    require_once dirname(__DIR__, 2) . '/src/tests/' . $suite . '.php';
    $class = 'App\\Tests\\' . $suite;
    $ok = (new $class())->run() && $ok;
}
require __DIR__ . '/context-files-backend.php';
exit($ok ? 0 : 1);
