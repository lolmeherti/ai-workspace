@echo off
echo ==============================================
echo  Localsy - Docker Build and Push Assistant
echo ==============================================
echo.

:: 1. Build the Docker Image
echo [+] Building Docker image deampuleadd/ai_php_web:latest...
docker build -t deampuleadd/ai_php_web:latest .

if %ERRORLEVEL% neq 0 (
    echo.
    echo [-] Error: Docker build failed!
    pause
    exit /b %ERRORLEVEL%
)

echo.
echo [+] Build successful!
echo.

:: 2. Optional Push Prompt
set /p choice="Do you want to push this image to Docker Hub? (y/n): "

if /i "%choice%"=="y" (
    echo.
    echo [+] Verifying Docker registry authentication...
    echo If you are not logged in, please enter your Docker Hub credentials when prompted.
    docker login
    
    echo.
    echo [+] Pushing deampuleadd/ai_php_web:latest to Docker Hub...
    docker push deampuleadd/ai_php_web:latest
    
    if %ERRORLEVEL% neq 0 (
        echo.
        echo [-] Error: Docker push failed! Check your registry permissions.
    ) else (
        echo.
        echo [+] Successfully pushed to Docker Hub!
    )
) else (
    echo.
    echo [+] Push skipped. Your image is stored locally.
)

echo.
pause