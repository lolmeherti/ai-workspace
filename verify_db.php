<?php
require_once 'src/App/Database.php';

$db = new \App\Database();
echo "Creating user_profiles table...\n";
$db->query("CREATE TABLE IF NOT EXISTS user_profiles (
    id INT PRIMARY KEY,
    profile_text TEXT
)");
echo "Table created or already exists.\n";
?>
