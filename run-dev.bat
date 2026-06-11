@echo off
title Localsy Hot-Development Mode
echo ==============================================
echo  Localsy - Hot-Development Environment
echo ==============================================
echo.

echo [+] Booting up localsy launcher in background...
start "" "localsy.exe" -debug

echo [+] Waiting for local AI engine to start...
:wait_port
netstat -ano | findstr ":1234" | findstr "LISTENING" >nul 2>&1
if errorlevel 1 (
    timeout /t 2 /nobreak >nul
    goto wait_port
)

echo [+] AI engine detected on port 1234. Settling background processes...
timeout /t 5 /nobreak >nul

echo [+] Syncing network environment parameters...
copy /y "%LOCALAPPDATA%\localsy\.env" "%~dp0.env" >nul

set WORKSPACE_DIR=%~dp0
if "%WORKSPACE_DIR:~-1%"=="\" set WORKSPACE_DIR=%WORKSPACE_DIR:~0,-1%

set DRIVE_LETTER=%WORKSPACE_DIR:~0,1%
set DRIVE_LETTER_LOWER=c
if /i "%DRIVE_LETTER%"=="A" set DRIVE_LETTER_LOWER=a
if /i "%DRIVE_LETTER%"=="B" set DRIVE_LETTER_LOWER=b
if /i "%DRIVE_LETTER%"=="C" set DRIVE_LETTER_LOWER=c
if /i "%DRIVE_LETTER%"=="D" set DRIVE_LETTER_LOWER=d
if /i "%DRIVE_LETTER%"=="E" set DRIVE_LETTER_LOWER=e
if /i "%DRIVE_LETTER%"=="F" set DRIVE_LETTER_LOWER=f
if /i "%DRIVE_LETTER%"=="G" set DRIVE_LETTER_LOWER=g

set RAW_PATH=%WORKSPACE_DIR:~2%
set WSL_PATH=%RAW_PATH:\=/%
set WSL_PATH=/mnt/%DRIVE_LETTER_LOWER%%WSL_PATH%

echo [+] Hot-stopping the production web container...
wsl -d localsy-docker-backend docker stop ai_php_web >nul 2>&1
wsl -d localsy-docker-backend docker rm ai_php_web >nul 2>&1

echo [+] Resolving Windows Host IP from WSL...
for /f "usebackq tokens=3" %%A in (`wsl -d localsy-docker-backend sh -c "ip route | grep default | head -n1"`) do set WINDOWS_HOST_IP=%%A
if "%WINDOWS_HOST_IP%"=="" (
    for /f "usebackq tokens=2" %%A in (`wsl -d localsy-docker-backend sh -c "grep nameserver /etc/resolv.conf | head -n1"`) do set WINDOWS_HOST_IP=%%A
)
echo [+] Windows Host IP detected: %WINDOWS_HOST_IP%

echo [+] Hot-stopping the production web container...
wsl -d localsy-docker-backend docker stop ai_php_web >nul 2>&1
wsl -d localsy-docker-backend docker rm ai_php_web >nul 2>&1

echo [+] Launching development container with live volume mounts...
wsl -d localsy-docker-backend env WINDOWS_HOST_IP="%WINDOWS_HOST_IP%" docker compose -p localsy --project-directory "%WSL_PATH%" -f "%WSL_PATH%/docker-compose.yml" up -d --no-deps web

echo.
echo ==========================================================
echo [+] SUCCESS! Development environment is fully active.
echo     - Local AI Engine: Running (Warming up in GPU)
echo     - PHP Web Server: Connected to /src/ with hot-reload
echo     - Reachable at: http://localhost:8080
echo.
echo  Keep this window open. Press any key to stop development.
echo ==========================================================
pause

echo.
echo [-] Shutting down development container...
wsl -d localsy-docker-backend env WINDOWS_HOST_IP="%WINDOWS_HOST_IP%" docker compose -p localsy --project-directory "%WSL_PATH%" -f "%WSL_PATH%/docker-compose.yml" down >nul 2>&1
echo [+] Done.