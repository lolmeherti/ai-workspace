<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use App\Config;
use App\Database;
use App\Services\AtomizationStats;

Config::load(dirname(__DIR__, 2));
$db = new Database();
$db->initTables();
$s = new AtomizationStats($db);
echo "seed ema: " . $s->consolidationMsEma() . "\n";
$s->recordConsolidation(5000);
$s->recordConsolidation(5000);
echo "after 2x5000 (alpha=0.3): " . round($s->consolidationMsEma(), 2) . "\n";
