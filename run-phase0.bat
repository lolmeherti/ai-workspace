@echo off
REM Phase 0: Run all 10 evaluation fixture captures
REM Requires: Docker Desktop running, dev containers up (run-dev.bat)

echo ========================================
echo  Phase 0 - Evaluation Fixture Capture
echo ========================================
echo.

REM Check Docker is running
wsl -d localsy-docker-backend docker ps >nul 2>&1
if errorlevel 1 (
    echo [ERROR] Docker is not running. Start Docker Desktop first.
    echo Then run: run-dev.bat
    pause
    exit /b 1
)

REM Check the web container exists
wsl -d localsy-docker-backend docker ps --filter "name=ai_php_web" --format "{{.Names}}" | findstr "ai_php_web" >nul
if errorlevel 1 (
    echo [ERROR] ai_php_web container is not running.
    echo Run run-dev.bat first.
    pause
    exit /b 1
)

echo [+] Docker and web container confirmed running.
echo [+] Running all 10 captures inside container...
echo.

wsl -d localsy-docker-backend docker exec ai_php_web php /var/www/html/tests/search-eval/capture-all.php

echo.
echo ========================================
echo  Capture complete.
echo  Fixtures saved to: src\tests\search-eval\
echo ========================================
pause
