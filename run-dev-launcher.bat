@echo off
title Localsy Launcher
echo ==============================================
echo  Localsy - Launcher Tab
echo ==============================================
echo.

echo [+] Clearing previous session logs...
if exist "%LOCALAPPDATA%\localsy\localsy.log" del /f /q "%LOCALAPPDATA%\localsy\localsy.log" >nul 2>&1
if exist "%~dp0src\db_errors.log" del /f /q "%~dp0src\db_errors.log" >nul 2>&1
if exist "%~dp0src\json_parser_errors.log" del /f /q "%~dp0src\json_parser_errors.log" >nul 2>&1
echo.

echo [+] Booting up localsy launcher...
set "LOCALSY_DEV_ENV=%~dp0.env"
localsy.exe -debug

echo.
echo [-] Localsy launcher exited.
pause
