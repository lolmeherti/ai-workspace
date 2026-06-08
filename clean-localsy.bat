@echo off
echo ==============================================
echo  Localsy - Safe Clean Reset Assistant
echo ==============================================
echo.

echo [+] Stopping llama-server...
taskkill /F /IM llama-server.exe >nul 2>&1

echo [+] Stopping localsy launcher...
taskkill /F /IM localsy.exe >nul 2>&1

echo [+] Shutting down WSL to release virtual disk locks...
wsl --shutdown >nul 2>&1

echo [+] Waiting for files to release...
timeout /t 2 /nobreak >nul

echo [+] Safely cleaning AppData directories (preserving models)...
set "TARGET=%LOCALAPPDATA%\localsy"

if exist "%TARGET%" (
    if exist "%TARGET%\docker-compose.yml" del /f /q "%TARGET%\docker-compose.yml" >nul 2>&1
    if exist "%TARGET%\.env" del /f /q "%TARGET%\.env" >nul 2>&1
    if exist "%TARGET%\localsy.log" del /f /q "%TARGET%\localsy.log" >nul 2>&1
    
    if exist "%TARGET%\bin" rmdir /s /q "%TARGET%\bin" >nul 2>&1
    if exist "%TARGET%\searxng" rmdir /s /q "%TARGET%\searxng" >nul 2>&1
    
    echo [+] Safe AppData cleanup complete. Models folder preserved.
) else (
    echo [i] AppData directory does not exist. Nothing to clear.
)

echo.
echo [+] Cleanup complete! You can now run the setup script.
pause