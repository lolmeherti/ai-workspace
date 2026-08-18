<?php
declare(strict_types=1);
// Prints only the KEY portion of each memory (field label before the first colon)
// and the value's character length. Never prints the value itself.
require dirname(__DIR__, 2) . '/vendor/autoload.php';
\App\Config::load(dirname(__DIR__, 2));
$db = new \App\Database();

$rows = $db->query("SELECT id, memory_text, created_at FROM memories ORDER BY id");
foreach ($rows as $r) {
    $text = $r['memory_text'];
    $colon = mb_strpos($text, ':');
    $key = $colon === false ? '(no colon)' : trim(mb_substr($text, 0, $colon));
    $valLen = $colon === false ? mb_strlen($text) : mb_strlen(trim(mb_substr($text, $colon + 1)));
    echo "id={$r['id']} created={$r['created_at']} key_len=" . mb_strlen($key) . " val_len={$valLen} key=[" . $key . "]\n";
}
