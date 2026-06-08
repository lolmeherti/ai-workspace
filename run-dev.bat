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
copy /y "%LOCALAPPDATA%\localsy\.env" "C:\Users\Admin-PC\Desktop\ai-workspace\env" >nul

:: 4. Stop the embedded production container
echo [+] Hot-stopping the production web container...
wsl -d localsy-docker-backend docker stop ai_php_web >nul 2>&1

:: 5. Start the development container, joining the existing "localsy" network
echo [+] Launching development container with live volume mounts...
wsl -d localsy-docker-backend docker compose -p localsy -f docker-compose.yml up -d --no-deps web

echo.
echo ==========================================================
echo [^+] SUCCESS! Development environment is fully active.
echo     - Local AI Engine: Running (Warming up in GPU)
echo     - PHP Web Server: Connected to /src/ with hot-reload
echo     - Reachable at: http://localhost:8080
echo.
echo  Keep this window open. Press any key to stop development.
echo ==========================================================
pause

echo.
echo [-] Shutting down development container...
wsl -d localsy-docker-backend docker compose -p localsy -f docker-compose.yml down >nul 2>&1
echo [^+] Done.