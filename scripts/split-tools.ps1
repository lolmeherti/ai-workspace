$root = "C:\Users\Admin-PC\Desktop\ai-workspace"
$src = Join-Path $root "src\App\Services\ToolExecutionService.php"
$toolsDir = Join-Path $root "src\App\Services\Tools"
New-Item -ItemType Directory -Force -Path $toolsDir | Out-Null
$lines = Get-Content $src

function Write-ToolFile($name, $startLine, $endLine, $useLines) {
    $body = ($lines[($startLine - 1)..($endLine - 1)] -join "`n")
    $body = $body -replace '\$this->makeTodoistRequest', '$this->todoist->request'
    $uses = ($useLines -join "`n")
    $content = @"
<?php

namespace App\Services\Tools;

$uses

class $name
{
    use ToolStreamHelper;

    public function __construct(
        private \App\Database `$db,
        private \App\AgentManager `$agent,
        private string `$uploadDir,
        private TodoistApiClient `$todoist
    ) {
    }

    public function execute(array `$toolData, int `$sessionId, array `$messages, callable `$emit, string `$cleanJson): string
    {
$body
    }
}
"@
    Set-Content (Join-Path $toolsDir "$name.php") -Value $content -Encoding UTF8
    Write-Host "Wrote $name"
}

# TodoistApiClient
$todoistBody = ($lines[589..629] -join "`n") -replace 'makeTodoistRequest', 'request'
$client = @"
<?php

namespace App\Services\Tools;

use App\Config;

class TodoistApiClient
{
$todoistBody
}
"@
Set-Content (Join-Path $toolsDir "TodoistApiClient.php") -Value $client -Encoding UTF8

# ToolStreamHelper - write separately
Copy-Item (Join-Path $root "src\App\Services\Tools\ToolStreamHelper.php") -ErrorAction SilentlyContinue

Write-ToolFile 'SearchFilesTool' 55 194 @('use App\AgentManager;')
Write-ToolFile 'CreateTodoistTaskTool' 197 261 @('use App\Agents\SchedulingAgent;')
Write-ToolFile 'GetTodoistTasksTool' 264 345 @('use App\Config;', 'use App\Agents\TaskMatcher;')
Write-ToolFile 'DeleteTodoistTaskTool' 348 388 @('use App\Agents\TaskMatcher;')
Write-ToolFile 'UpdateTodoistTaskTool' 391 475 @('use App\Agents\TaskMatcher;')
Write-ToolFile 'GetEmailBriefingTool' 478 580 @('use App\Agents\SchedulingAgent;', 'use App\Services\EmailService;')

Write-Host "Done"
