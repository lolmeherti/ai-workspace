@echo off
:: Ensure the script is running as Administrator
net session >nul 2>&1
if %errorLevel% neq 0 (
    echo [-] Error: Please run this script as Administrator.
    pause
    exit /b
)

echo ===================================================
echo  Localsy - First-Time Administrator Provisioning
echo ===================================================
echo.

set "TARGET_DIR=%LOCALAPPDATA%\localsy"

:: 1. Create AppData working directory if it doesn't exist
if not exist "%TARGET_DIR%" (
    echo [+] Creating working directories...
    mkdir "%TARGET_DIR%" >nul 2>&1
)

:: 2. Write the safety marker file so the Go launcher knows exclusions are configured
echo excluded > "%TARGET_DIR%\.excluded"
echo [^+] Safety marker file created.

:: 3. Exclude the Localsy folder from Windows Defender scans
echo [+] Whitelisting Localsy directory in Windows Defender...
powershell -Command "Add-MpPreference -ExclusionPath '%TARGET_DIR%'" >nul 2>&1
if %errorLevel% equ 0 (
    echo [^+] Windows Defender exclusions added successfully.
) else (
    echo [-] Warning: Failed to configure Windows Defender exclusions.
)

:: 4. Add standard Windows Defender Firewall inbound rule for llama-server
echo [+] Configuring Windows Defender Firewall rule for Port 1234...
netsh advfirewall firewall show rule name="Localsy AI Engine" >nul 2>&1
if %errorLevel% neq 0 (
    netsh advfirewall firewall add rule name="Localsy AI Engine" dir=in action=allow protocol=TCP localport=1234 profile=private,public >nul 2>&1
    echo [^+] Windows Firewall rule added successfully.
) else (
    echo [i] Windows Firewall rule already exists.
)

:: 5. Configure Hyper-V VM Creator Firewall for WSL (Consistent across all Windows 11 installs)
echo [+] Configuring Hyper-V VM Firewall settings for WSL...
powershell -Command "Set-NetFirewallHyperVVMSetting -Name '{40E0AC32-46A5-438A-A0B2-2B479E8F2E90}' -Enabled False" >nul 2>&1
if %errorLevel% equ 0 (
    echo [^+] Hyper-V VM Firewall configured successfully.
) else (
    echo [-] Warning: Failed to configure Hyper-V VM settings.
)

echo.
echo [+] Provisioning complete! You can now run localsy.exe safely.
pause