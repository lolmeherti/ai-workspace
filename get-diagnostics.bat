@echo off
echo ==============================================
echo  Localsy - Automated Diagnostic Collector
echo ==============================================
echo.
echo This script collects error logs to help troubleshoot issues.
echo It does not collect any personal data.
echo.

set "SOURCE_DIR=%LOCALAPPDATA%\localsy"
set "DEST_DIR=%USERPROFILE%\Desktop\Localsy-Diagnostics"

echo [+] Creating Diagnostic folder on your Desktop...
if exist "%DEST_DIR%" rmdir /s /q "%DEST_DIR%" >nul 2>&1
mkdir "%DEST_DIR%" >nul 2>&1

:: 1. Copy central log file
if exist "%SOURCE_DIR%\localsy.log" (
    copy "%SOURCE_DIR%\localsy.log" "%DEST_DIR%\localsy.log" >nul
    echo [^+] Captured: System Logs
) else (
    echo [-] Warning: Central system log not found.
)

:: 2. Copy current configurations (without passwords, if possible, or warning them)
if exist "%SOURCE_DIR%\.env" (
    copy "%SOURCE_DIR%\.env" "%DEST_DIR%\environment.txt" >nul
    echo [^+] Captured: Configuration settings
)

:: 3. Export WSL and Docker diagnostic state
echo [+] Querying WSL and Container states...
echo === WSL List === > "%DEST_DIR%\wsl-state.txt"
wsl --list --verbose >> "%DEST_DIR%\wsl-state.txt" 2>&1

echo. >> "%DEST_DIR%\wsl-state.txt"
echo === Docker Container Status === >> "%DEST_DIR%\wsl-state.txt"
wsl -d localsy-docker-backend docker ps -a >> "%DEST_DIR%\wsl-state.txt" 2>&1

echo. >> "%DEST_DIR%\wsl-state.txt"
echo === Docker Disk Space Usage === >> "%DEST_DIR%\wsl-state.txt"
wsl -d localsy-docker-backend df -h >> "%DEST_DIR%\wsl-state.txt" 2>&1

echo.
echo ==========================================================
echo [^+] Success! Diagnostic files saved to your Desktop at:
echo     %DEST_DIR%
echo.
echo Please zip this folder and send it to support.
echo ==========================================================
pause