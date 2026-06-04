<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/vendor/autoload.php';

use App\Config;
use App\Database;

Config::load(__DIR__);

try {
    $db = new Database();
    $db->nukeAndRebuildTables();
    echo "✅ Database successfully nuked and migrated using internal wrapper methods.";
} catch (Exception $e) {
    echo "❌ Migration failed: " . htmlspecialchars($e->getMessage());
}