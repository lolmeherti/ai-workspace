param(
    [Parameter(Mandatory = $false)]
    [string]$Query = "Gemma 4 E4B mmproj llama.cpp"
)

$ErrorActionPreference = "Stop"
$prefix = "http://127.0.0.1:8765/"

function Receive-WebSocketText {
    param([System.Net.WebSockets.WebSocket]$Socket)

    $buffer = New-Object byte[] 65536
    $stream = New-Object System.IO.MemoryStream

    try {
        do {
            $segment = [ArraySegment[byte]]::new($buffer)
            $result = $Socket.ReceiveAsync(
                $segment,
                [Threading.CancellationToken]::None
            ).GetAwaiter().GetResult()

            if ($result.MessageType -eq [System.Net.WebSockets.WebSocketMessageType]::Close) {
                return $null
            }

            $stream.Write($buffer, 0, $result.Count)
        } while (-not $result.EndOfMessage)

        return [Text.Encoding]::UTF8.GetString($stream.ToArray())
    }
    finally {
        $stream.Dispose()
    }
}

function Send-WebSocketJson {
    param(
        [System.Net.WebSockets.WebSocket]$Socket,
        [object]$Value
    )

    $json = $Value | ConvertTo-Json -Depth 8 -Compress
    $bytes = [Text.Encoding]::UTF8.GetBytes($json)
    $segment = [ArraySegment[byte]]::new($bytes)

    $Socket.SendAsync(
        $segment,
        [System.Net.WebSockets.WebSocketMessageType]::Text,
        $true,
        [Threading.CancellationToken]::None
    ).GetAwaiter().GetResult()
}

$listener = [System.Net.HttpListener]::new()
$listener.Prefixes.Add($prefix)
$listener.Start()

Write-Host "Localsy test bridge listening on ws://127.0.0.1:8765/"
Write-Host "Waiting for Edge extension..."
Write-Host "(If it does not connect quickly, click Reload on edge://extensions.)"

try {
    $context = $listener.GetContext()

    if (-not $context.Request.IsWebSocketRequest) {
        $context.Response.StatusCode = 400
        $context.Response.Close()
        throw "Received HTTP request instead of WebSocket upgrade."
    }

    $wsContext = $context.AcceptWebSocketAsync([System.Management.Automation.Language.NullString]::Value).GetAwaiter().GetResult()
    $socket = $wsContext.WebSocket

    $helloRaw = Receive-WebSocketText -Socket $socket
    if (-not $helloRaw) {
        throw "Extension disconnected before hello."
    }

    $hello = $helloRaw | ConvertFrom-Json
    Write-Host "Bridge connected: $($hello.bridge_version)"

    $requestId = [Guid]::NewGuid().ToString()
    Send-WebSocketJson -Socket $socket -Value @{
        action     = "search"
        request_id = $requestId
        query      = $Query
    }

    Write-Host "Search dispatched: $Query"

    while ($socket.State -eq [System.Net.WebSockets.WebSocketState]::Open) {
        $raw = Receive-WebSocketText -Socket $socket
        if (-not $raw) { break }

        $message = $raw | ConvertFrom-Json

        if ($message.type -eq "ping") {
            continue
        }

        if ($message.type -eq "search_result" -and $message.request_id -eq $requestId) {
            Write-Host "Status: $($message.status)"
            Write-Host ""
            $message.results | Format-Table position, title, url -Wrap
            Write-Host ""
            Write-Host "Raw JSON:"
            $message | ConvertTo-Json -Depth 8
            break
        }

        Write-Host "Other message: $raw"
    }

    if ($socket.State -eq [System.Net.WebSockets.WebSocketState]::Open) {
        $socket.CloseAsync(
            [System.Net.WebSockets.WebSocketCloseStatus]::NormalClosure,
            "test complete",
            [Threading.CancellationToken]::None
        ).GetAwaiter().GetResult()
    }
}
finally {
    $listener.Stop()
    $listener.Close()
}
