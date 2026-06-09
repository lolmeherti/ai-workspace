@echo off
title Localsy Hot-Development Mode
echo ==============================================
echo  Localsy - Hot-Development Environment
echo ==============================================
echo.

:: 1. Launch localsy.exe in the background WITH the debug flag automatically appended
echo [+] Booting up localsy launcher with -debug and warming up background AI engine...
start "" "localsy.exe" -debug

:: 2. Wait for the Alpine VM and Docker to initialize
echo [+] Waiting for WSL backend and Docker sockets to bind...
timeout /t 10 /nobreak >nul

:: 3. Sync the dynamically generated .env (with the correct host IP)
echo [+] Syncing network environment parameters...
copy /y "%LOCALAPPDATA%\localsy\.env" "%~dp0.env" >nul

:: 4. Convert Windows path to WSL path natively (Matches Go launcher's toWslPath logic)
set WORKSPACE_DIR=%~dp0
if "%WORKSPACE_DIR:~-1%"=="\" set WORKSPACE_DIR=%WORKSPACE_DIR:~0,-1%

:: Extract drive letter and make it lowercase
set DRIVE_LETTER=%WORKSPACE_DIR:~0,1%
set DRIVE_LETTER_LOWER=c
if /i "%DRIVE_LETTER%"=="A" set DRIVE_LETTER_LOWER=a
if /i "%DRIVE_LETTER%"=="B" set DRIVE_LETTER_LOWER=b
if /i "%DRIVE_LETTER%"=="C" set DRIVE_LETTER_LOWER=c
if /i "%DRIVE_LETTER%"=="D" set DRIVE_LETTER_LOWER=d
if /i "%DRIVE_LETTER%"=="E" set DRIVE_LETTER_LOWER=e
if /i "%DRIVE_LETTER%"=="F" set DRIVE_LETTER_LOWER=f
if /i "%DRIVE_LETTER%"=="G" set DRIVE_LETTER_LOWER=g

:: Strip drive prefix and convert backslashes to forward slashes
set RAW_PATH=%WORKSPACE_DIR:~2%
set WSL_PATH=%RAW_PATH:\=/%
set WSL_PATH=/mnt/%DRIVE_LETTER_LOWER%%WSL_PATH%

:: 5. Hot-stopping and removing the production web container to prevent naming conflicts
echo [+] Hot-stopping the production web container...
wsl -d localsy-docker-backend docker stop ai_php_web >nul 2>&1
wsl -d localsy-docker-backend docker rm ai_php_web >nul 2>&1

:: 6. Start the development container, joining the existing "localsy" network
echo [+] Launching development container with live volume mounts...
wsl -d localsy-docker-backend docker compose -p localsy --project-directory "%WSL_PATH%" -f "%WSL_PATH%/docker-compose.yml" up -d --no-deps web

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
wsl -d localsy-docker-backend docker compose -p localsy --project-directory "%WSL_PATH%" -f "%WSL_PATH%/docker-compose.yml" down >nul 2>&1
echo [+] Done.