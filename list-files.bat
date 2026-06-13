@echo off
echo Scanning directory (excluding common library and build folders)...
echo.

powershell -NoProfile -ExecutionPolicy Bypass -Command "Get-ChildItem -Path . -Recurse -File | Where-Object { $parts = $_.FullName -split '[\\/]'; !($parts | Where-Object { @('node_modules', 'vendor', '.git', '.github', '.idea', '.vscode', 'bin', 'obj', '__pycache__', '.pytest_cache', 'dist', 'build', 'target', '.venv', 'env', 'venv', '.next', '.nuxt', 'out', 'bower_components') -contains $_ }) } | ForEach-Object { Resolve-Path -Relative $_.FullName }"

echo.
pause