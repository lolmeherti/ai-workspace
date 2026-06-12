# Split Database schema, create ActionRouter, PageDataLoader, update ApiAction, delete LlmClient
$root = "C:\Users\Admin-PC\Desktop\ai-workspace\src"

# Schema extraction from Database.php
$dbFile = Join-Path $root "App\Database.php"
$dbLines = Get-Content $dbFile
$schemaStart = ($dbLines | Select-String -Pattern 'public function nukeAndRebuildTables' | Select-Object -First 1).LineNumber
$schemaEnd = $dbLines.Count
$schemaBody = ($dbLines[($schemaStart - 1)..($schemaEnd - 1)] -join "`n")

$dbDir = Join-Path $root "App\Database"
New-Item -ItemType Directory -Force -Path $dbDir | Out-Null

$schemaPhp = @"
<?php

namespace App\Database;

use App\Database as DatabaseConnection;

class Schema
{
    public function __construct(private DatabaseConnection `$db)
    {
    }

$($schemaBody -replace 'public function nukeAndRebuildTables', 'public function rebuild')
}
"@
Set-Content (Join-Path $dbDir "Schema.php") -Value $schemaPhp -Encoding UTF8

# Trim Database.php - keep lines before schema method
$keepLines = $dbLines[0..($schemaStart - 2)]
$newDb = ($keepLines -join "`n") + @"

    public function nukeAndRebuildTables(): void
    {
        (new Schema(`$this))->rebuild();
    }
}
"@
Set-Content $dbFile -Value $newDb -Encoding UTF8

Write-Host "Schema split done"
