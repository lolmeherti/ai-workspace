$root = "C:\Users\Admin-PC\Desktop\ai-workspace\src"
$actionsDir = Join-Path $root "App\Actions"

function Get-MethodBody {
    param([string[]]$Lines, [int]$StartLine)
    $depth = 0
    $started = $false
    $body = @()
    for ($i = $StartLine - 1; $i -lt $Lines.Count; $i++) {
        $line = $Lines[$i]
        if (-not $started) {
            if ($line -match 'function\s+') { $started = $true; $body += $line; $depth += ([regex]::Matches($line, '\{')).Count - ([regex]::Matches($line, '\}')).Count; continue }
            continue
        }
        $body += $line
        $depth += ([regex]::Matches($line, '\{')).Count - ([regex]::Matches($line, '\}')).Count
        if ($depth -le 0) { break }
    }
    return $body
}

function Convert-MethodToExecute {
    param([string[]]$Body, [bool]$IsFirst = $true)
    $result = @()
    foreach ($line in $Body) {
        if ($IsFirst -and $line -match '^\s*private function (\w+)') {
            $result += $line -replace 'private function \w+', 'public function execute'
            $IsFirst = $false
        } else {
            $result += $line
        }
    }
    return $result
}

function Write-ActionFile {
    param(
        [string]$SubDir,
        [string]$ClassName,
        [string[]]$MethodLineNums,
        [string]$SourceFile,
        [string]$Constructor,
        [string[]]$UseStatements = @(),
        [string]$TraitLine = ""
    )
    $lines = Get-Content $SourceFile
    $outDir = Join-Path $actionsDir $SubDir
    New-Item -ItemType Directory -Force -Path $outDir | Out-Null

    $useBlock = ($UseStatements | ForEach-Object { "use $_;" }) -join "`n"
    $methodBlocks = @()
    $first = $true
    foreach ($ln in $MethodLineNums) {
        $body = Get-MethodBody -Lines $lines -StartLine $ln
        $methodBlocks += Convert-MethodToExecute -Body $body -IsFirst $first
        $first = $false
    }

    $php = @"
<?php

namespace App\Actions\$($SubDir -replace '/', '\');

use App\Actions\BaseAction;
$useBlock

class $ClassName extends BaseAction
{
$TraitLine
$Constructor

$($methodBlocks -join "`n")
}
"@
    Set-Content (Join-Path $outDir "$ClassName.php") -Value $php -Encoding UTF8
    Write-Host "Created $ClassName"
}

# BaseAction
@'
<?php

namespace App\Actions;

use App\Enums\Tab;

abstract class BaseAction
{
    protected function jsonResponse(array $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    protected function redirect(string $url): void
    {
        header("Location: " . $url);
        exit;
    }

    protected function buildUrl(int $sessionId, Tab $tab): string
    {
        return "index.php?session_id=" . $sessionId . "&tab=" . $tab->value;
    }

    protected function isApiRequest(): bool
    {
        $acceptHeader = $_SERVER['HTTP_ACCEPT'] ?? '';
        return strpos($acceptHeader, 'application/json') !== false || strpos($acceptHeader, 'text/event-stream') !== false;
    }
}
'@ | Set-Content (Join-Path $actionsDir "BaseAction.php") -Encoding UTF8

$fileCtrl = Join-Path $root "App\Controllers\FileController.php"
$dbCtor = "    public function __construct(private `$db) {}"

# Trait for shared file registration
$traitBody = Get-MethodBody -Lines (Get-Content $fileCtrl) -StartLine 474
$traitPhp = @"
<?php

namespace App\Actions\File;

trait RegistersUploadedFiles
{
$($traitBody -join "`n")
}
"@
Set-Content (Join-Path $actionsDir "File\RegistersUploadedFiles.php") -Value $traitPhp -Encoding UTF8

Write-ActionFile -SubDir "File" -ClassName "FileSyncAction" -MethodLineNums @(92) -SourceFile $fileCtrl -Constructor $dbCtor -TraitLine "    use RegistersUploadedFiles;"
Write-ActionFile -SubDir "File" -ClassName "FileDraftOpenAction" -MethodLineNums @(161) -SourceFile $fileCtrl -Constructor $dbCtor
Write-ActionFile -SubDir "File" -ClassName "FileDraftUpdateAction" -MethodLineNums @(223) -SourceFile $fileCtrl -Constructor $dbCtor
Write-ActionFile -SubDir "File" -ClassName "FileDraftSaveAction" -MethodLineNums @(299) -SourceFile $fileCtrl -Constructor $dbCtor
Write-ActionFile -SubDir "File" -ClassName "FileDraftDiscardAction" -MethodLineNums @(347) -SourceFile $fileCtrl -Constructor $dbCtor
Write-ActionFile -SubDir "File" -ClassName "FileDraftDeleteBlocksAction" -MethodLineNums @(377) -SourceFile $fileCtrl -Constructor $dbCtor
Write-ActionFile -SubDir "File" -ClassName "FileUploadAction" -MethodLineNums @(442) -SourceFile $fileCtrl -Constructor $dbCtor -TraitLine "    use RegistersUploadedFiles;"
Write-ActionFile -SubDir "File" -ClassName "FileSearchAction" -MethodLineNums @(586, 748) -SourceFile $fileCtrl -Constructor $dbCtor
Write-ActionFile -SubDir "File" -ClassName "FileDeleteAction" -MethodLineNums @(675) -SourceFile $fileCtrl -Constructor $dbCtor
Write-ActionFile -SubDir "File" -ClassName "FileExplorerAction" -MethodLineNums @(779) -SourceFile $fileCtrl -Constructor $dbCtor
Write-ActionFile -SubDir "File" -ClassName "FileContentAction" -MethodLineNums @(807) -SourceFile $fileCtrl -Constructor $dbCtor

$chatCtrl = Join-Path $root "App\Controllers\ChatController.php"
$chatCtor = @"
    public function __construct(
        private `$db,
        private `$chatSessionRepository,
        private `$agentManager,
        private `$memoryExtractor,
        private `$status
    ) {}
"@
$chatUses = @(
    "App\Repositories\ChatSessionRepository",
    "App\ChatManager",
    "App\Enums\Action",
    "App\Enums\ApiAction",
    "App\Enums\Tab",
    "App\Agents\SearchDecider",
    "App\Agents\SemanticCacheEvaluator",
    "App\Agents\ContextCondenser",
    "App\Agents\MemorySelector",
    "App\Agents\SchedulingAgent"
)

Write-ActionFile -SubDir "Chat" -ClassName "ChatTodoistCreateAction" -MethodLineNums @(107) -SourceFile $chatCtrl -Constructor $chatCtor -UseStatements $chatUses
Write-ActionFile -SubDir "Chat" -ClassName "ChatTodoistDeleteAction" -MethodLineNums @(196) -SourceFile $chatCtrl -Constructor $chatCtor -UseStatements $chatUses
Write-ActionFile -SubDir "Chat" -ClassName "ChatReplyAssistAction" -MethodLineNums @(265) -SourceFile $chatCtrl -Constructor $chatCtor -UseStatements $chatUses
Write-ActionFile -SubDir "Chat" -ClassName "ChatBriefingStreamAction" -MethodLineNums @(307) -SourceFile $chatCtrl -Constructor $chatCtor -UseStatements $chatUses
Write-ActionFile -SubDir "Chat" -ClassName "ChatStarAction" -MethodLineNums @(496) -SourceFile $chatCtrl -Constructor $chatCtor -UseStatements $chatUses
Write-ActionFile -SubDir "Chat" -ClassName "ChatCondenseAction" -MethodLineNums @(516) -SourceFile $chatCtrl -Constructor $chatCtor -UseStatements $chatUses
Write-ActionFile -SubDir "Chat" -ClassName "ChatStreamAction" -MethodLineNums @(545) -SourceFile $chatCtrl -Constructor $chatCtor -UseStatements $chatUses
Write-ActionFile -SubDir "Chat" -ClassName "ChatSessionDeleteAction" -MethodLineNums @(585) -SourceFile $chatCtrl -Constructor $chatCtor -UseStatements $chatUses

$emailCtrl = Join-Path $root "App\Controllers\EmailController.php"
$emailCtor = "    public function __construct(private `$db) {}"
$emailUses = @("App\Database", "App\Enums\Action", "Webklex\PHPIMAP\ClientManager", "PHPMailer\PHPMailer\PHPMailer")

Write-ActionFile -SubDir "Email" -ClassName "EmailAccountAddAction" -MethodLineNums @(47) -SourceFile $emailCtrl -Constructor $emailCtor -UseStatements $emailUses
Write-ActionFile -SubDir "Email" -ClassName "EmailAccountDeleteAction" -MethodLineNums @(84) -SourceFile $emailCtrl -Constructor $emailCtor -UseStatements $emailUses
Write-ActionFile -SubDir "Email" -ClassName "EmailListAction" -MethodLineNums @(93) -SourceFile $emailCtrl -Constructor $emailCtor -UseStatements $emailUses
Write-ActionFile -SubDir "Email" -ClassName "EmailBodyAction" -MethodLineNums @(306) -SourceFile $emailCtrl -Constructor $emailCtor -UseStatements $emailUses
Write-ActionFile -SubDir "Email" -ClassName "EmailReplyAction" -MethodLineNums @(486) -SourceFile $emailCtrl -Constructor $emailCtor -UseStatements $emailUses

Write-Host "All actions created"
