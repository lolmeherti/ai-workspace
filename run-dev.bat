@echo off
title Localsy Hot-Development Mode
echo ==============================================
echo  Localsy - Hot-Development Environment
echo ==============================================
echo.

where wt >nul 2>&1
if errorlevel 1 (
    echo [-] Windows Terminal ^(wt.exe^) not found.
    echo     Install it from the Microsoft Store, then run this again.
    echo.
    pause
    exit /b 1
)

set "WORKSPACE_DIR=%~dp0"
if "%WORKSPACE_DIR:~-1%"=="\" set "WORKSPACE_DIR=%WORKSPACE_DIR:~0,-1%"

echo [+] Launching Windows Terminal with launcher and docker tabs...
wt new-tab -d "%WORKSPACE_DIR%" cmd /k "run-dev-launcher.bat" ; new-tab -d "%WORKSPACE_DIR%" cmd /k "run-dev-docker.bat"
