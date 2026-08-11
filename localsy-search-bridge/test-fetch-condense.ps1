param(
    [Parameter(Mandatory = $true)]
    [string]$Url
)

$ErrorActionPreference = "Stop"
[Console]::OutputEncoding = [Text.Encoding]::UTF8
$BRIDGE = "http://127.0.0.1:9876"

Write-Host "==================================================" -ForegroundColor Cyan
Write-Host " Localsy Bridge: Fetch + LLM Condense" -ForegroundColor Cyan
Write-Host " URL: $Url" -ForegroundColor Cyan
Write-Host "==================================================" -ForegroundColor Cyan
Write-Host ""

# Step 1: Check bridge
Write-Host "[1/4] Checking bridge status..." -ForegroundColor Yellow
$status = Invoke-RestMethod -Uri "$BRIDGE/bridge/status" -Method Get -ErrorAction SilentlyContinue
if (-not $status -or -not $status.connected) {
    Write-Host "ERROR: Bridge not connected. Is localsy.exe running and extension loaded?" -ForegroundColor Red
    Write-Host "  -> Check edge://extensions for 'Localsy Search Bridge' (should show Active)"
    Write-Host "  -> Verify with: curl http://localhost:9876/bridge/status"
    exit 1
}
Write-Host "  Bridge connected." -ForegroundColor Green

# Step 2: Fetch page
Write-Host "[2/4] Fetching page via bridge (90s timeout)..." -ForegroundColor Yellow
$requestId = [Guid]::NewGuid().ToString()

try {
    $fetchResult = Invoke-RestMethod -Uri "$BRIDGE/bridge/fetch" `
        -Method Post `
        -ContentType "application/json" `
        -Body (ConvertTo-Json -Depth 1 -Compress @{ url = $Url; request_id = $requestId }) `
        -TimeoutSec 90
} catch {
    Write-Host "ERROR: Bridge fetch failed: $_" -ForegroundColor Red
    exit 1
}

if ($fetchResult.status -ne "success") {
    Write-Host "ERROR: Fetch status = $($fetchResult.status)" -ForegroundColor Red
    if ($fetchResult.error) { Write-Host "  Error: $($fetchResult.error)" -ForegroundColor Red }
    if ($fetchResult.content._challenge_reason) {
        Write-Host "  Challenge reason: $($fetchResult.content._challenge_reason)" -ForegroundColor Yellow
    }
    if ($fetchResult.content._debug) {
        Write-Host "  Debug:" -ForegroundColor DarkGray
        $fetchResult.content._debug | ForEach-Object { Write-Host "    $($_ | ConvertTo-Json -Depth 3 -Compress)" -ForegroundColor DarkGray }
    }
    # Show full response for debug
    $fetchResult | ConvertTo-Json -Depth 4
    exit 1
}

Write-Host "  Fetch succeeded." -ForegroundColor Green

$content = $fetchResult.content
$entities = $content.entities
$entityCount = if ($entities) { $entities.Count } else { 0 }
Write-Host "  Entities: $entityCount" -ForegroundColor Green
Write-Host ""

# Step 3: Display raw content
Write-Host "==================================================" -ForegroundColor Cyan
Write-Host " RAW EXTRACTED ENTITIES" -ForegroundColor Cyan
Write-Host "==================================================" -ForegroundColor Cyan
Write-Host ""

for ($i = 0; $i -lt $entityCount; $i++) {
    $e = $entities[$i]
    $label = if ($e.entity_type -eq "post") {
        "POST"
    } elseif ($e.entity_type -eq "comment") {
        "COMMENT"
    } elseif ($e.entity_type -eq "reply") {
        "REPLY (to $($e.parent_id))"
    } else {
        $e.entity_type.ToUpper()
    }

    Write-Host "-- [$($i+1)/$entityCount] $label --" -ForegroundColor Magenta
    if ($e.author)      { Write-Host "Author:    $($e.author)" }
    if ($e.score)       { Write-Host "Score:     $($e.score)" }
    if ($e.published)    { Write-Host "Published: $($e.published)" }
    if ($e.canonical_url) { Write-Host "URL:       $($e.canonical_url)" }
    if ($e.entity_id)   { Write-Host "ID:        $($e.entity_id)" }
    $bodyLen = if ($e.body) { $e.body.Length } else { 0 }
    Write-Host "Body size: $bodyLen chars"
    if ($bodyLen -eq 0) {
        Write-Host "WARNING: Empty body - rtjson-content extraction likely failed" -ForegroundColor Yellow
    }
    Write-Host ""
    Write-Host ($e.body -replace "`r`n", "`n")
    Write-Host ""
}

# Dump raw JSON for debugging empty bodies
Write-Host "==================================================" -ForegroundColor Cyan
Write-Host " RAW BRIDGE JSON (debug)" -ForegroundColor Cyan
Write-Host "==================================================" -ForegroundColor Cyan
$fetchResult | ConvertTo-Json -Depth 8
Write-Host ""

# Show _debug field if present (extractor diagnostic log)
if ($content._debug) {
  Write-Host "==================================================" -ForegroundColor Cyan
  Write-Host " EXTRACTOR DEBUG LOG" -ForegroundColor Cyan
  Write-Host "==================================================" -ForegroundColor Cyan
  $content._debug | ForEach-Object { $_ | ConvertTo-Json -Depth 4 -Compress }
  Write-Host ""
}

# Step 4: LLM condensation
Write-Host "==================================================" -ForegroundColor Cyan
Write-Host " LLM CONDENSATION" -ForegroundColor Cyan
Write-Host "==================================================" -ForegroundColor Cyan
Write-Host ""

# Assemble prompt from all entities
$bodyText = ""
$entityCounter = 0
foreach ($e in $entities) {
    $entityCounter++
    $tag = $e.entity_type.ToUpper()
    $label = "[E$entityCounter] $tag"
    if ($e.author) { $label += " by $($e.author)" }
    if ($e.score)  { $label += " (score: $($e.score))" }

    $bodyText += "$label`n"
    $bodyText += ($e.body -replace "`r`n", "`n")
    $bodyText += "`n`n"
}

$systemPrompt = @"
You are a content condensing assistant. Given extracted web page entities (posts, comments, articles), produce a concise summary.

Output format:
1. KEY FACTS: bullet list of the 3-7 most important factual claims, each with [EntityID] source tag
2. VERDICT: one-sentence assessment of overall reliability and signal quality
3. RAW_DETAIL: preserve any specific commands, flags, numbers, URLs, or code blocks verbatim

Be terse. Do not narrate your process. Only output the three sections above.
"@

$userMessage = @"
Condense this extracted content. The page title is: $($content.title)
URL: $($content.url)

CONTENT:
$bodyText
"@

$llmBody = @{
    model    = "local"
    messages = @(
        @{ role = "system"; content = $systemPrompt }
        @{ role = "user";   content = $userMessage }
    )
    temperature = 0.2
    max_tokens  = 2048
    stream      = $false
}

Write-Host "[3/4] Sending to LLM for condensation..." -ForegroundColor Yellow
Write-Host "  Endpoint: http://localhost:1234/v1/chat/completions" -ForegroundColor DarkGray
Write-Host "  Prompt size: $($userMessage.Length) chars, $entityCounter entities" -ForegroundColor DarkGray
Write-Host ""

try {
    $llmResponse = Invoke-RestMethod `
        -Uri "http://localhost:1234/v1/chat/completions" `
        -Method Post `
        -ContentType "application/json" `
        -Body (ConvertTo-Json -Depth 4 -Compress $llmBody) `
        -TimeoutSec 120
} catch {
    Write-Host "ERROR: LLM call failed: $_" -ForegroundColor Red
    Write-Host "  Is llama-server running on :1234?" -ForegroundColor Red
    exit 1
}

$reply = $llmResponse.choices[0].message.content

Write-Host "[4/4] LLM condensation:" -ForegroundColor Green
Write-Host $reply

# Summary
Write-Host ""
Write-Host "==================================================" -ForegroundColor Cyan
Write-Host " DONE" -ForegroundColor Cyan
Write-Host " URL:      $Url" -ForegroundColor White
Write-Host " Entities: $entityCount" -ForegroundColor White
Write-Host " Body:     $($bodyText.Length) chars sent to LLM" -ForegroundColor White
Write-Host " Reply:    $($reply.Length) chars" -ForegroundColor White
if ($llmResponse.usage) {
    Write-Host " Tokens:   prompt=$($llmResponse.usage.prompt_tokens) completion=$($llmResponse.usage.completion_tokens)" -ForegroundColor White
}
Write-Host "==================================================" -ForegroundColor Cyan
