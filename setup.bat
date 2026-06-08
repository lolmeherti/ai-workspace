@echo off
setlocal enabledelayedexpansion

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

:: 1. BIOS VIRTUALIZATION CHECK
echo [+] Checking CPU Virtualization status in BIOS...
powershell -Command "if ((Get-CimInstance -ClassName Win32_Processor -ErrorAction SilentlyContinue | Select-Object -First 1).VirtualizationFirmwareEnabled) { exit 0 } else { exit 1 }"
if %errorLevel% neq 0 goto :VirtualizationError
echo [^+] Virtualization is enabled in BIOS.

:: 2. WSL2 DETECTION & HEADLESS INSTALLATION
echo [+] Checking for Windows Subsystem for Linux (WSL)...
where wsl >nul 2>&1
if %errorLevel% neq 0 goto :WSLInstall
echo [^+] WSL is installed.

:: 3. Create AppData working directory if it doesn't exist
if not exist "%TARGET_DIR%" (
    echo [+] Creating working directories...
    mkdir "%TARGET_DIR%" >nul 2>&1
)

:: 4. Write the safety marker file so the Go launcher knows exclusions are configured
echo excluded > "%TARGET_DIR%\.excluded"
echo [^+] Safety marker file created.

:: 5. Exclude the Localsy folder from Windows Defender scans
echo [+] Whitelisting Localsy directory in Windows Defender...
powershell -Command "Add-MpPreference -ExclusionPath '%TARGET_DIR%'" >nul 2>&1
if %errorLevel% equ 0 (
    echo [^+] Windows Defender exclusions added successfully.
) else (
    echo [-] Warning: Failed to configure Windows Defender exclusions.
)

:: 6. Add standard Windows Defender Firewall inbound rule for llama-server
echo [+] Configuring Windows Defender Firewall rule for Port 1234...
netsh advfirewall firewall show rule name="Localsy AI Engine" >nul 2>&1
if %errorLevel% neq 0 (
    netsh advfirewall firewall add rule name="Localsy AI Engine" dir=in action=allow protocol=TCP localport=1234 profile=private,public >nul 2>&1
    echo [^+] Windows Firewall rule added successfully.
) else (
    echo [i] Windows Firewall rule already exists.
)

:: 7. Configure Hyper-V VM Creator Firewall for WSL (Consistent across all Windows 11 installs)
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
exit /b


:: =================================================================
:: ERROR HANDLERS & JUMP LABELS (Protects against parenthesis parsing bugs)
:: =================================================================

:VirtualizationError
echo.
echo =================================================================
echo  CRITICAL ERROR: CPU Hardware Virtualization is DISABLED in BIOS!
echo =================================================================
echo  WSL2 requires CPU virtualization to run Localsy's background services [3.2].
echo.
echo  [!] HOW TO FIX THIS (Requires a 1-minute BIOS change):
echo.
echo  1. Restart your PC. As soon as the screen goes black, tap the "Delete"
echo     or "F2" key repeatedly until your BIOS configuration screen opens.
echo.
echo  2. Enable the hypervisor setting depending on your CPU brand:
echo.
echo     For INTEL CPUs:
echo     - Navigate to: Advanced -> CPU Configuration (or Overclocking settings)
echo     - Locate: "Intel Virtualization Technology" (or "Intel VT-x" / "VMX")
echo     - Change setting to: ENABLED
echo.
echo     For AMD CPUs:
echo     - Navigate to: Advanced -> CPU Configuration (or M.I.T / Advanced Tweaker)
echo     - Locate: "SVM Mode" (or "Secure Virtual Machine" / "AMD-V")
echo     - Change setting to: ENABLED
echo.
echo  3. Press "F10" on your keyboard to Save and Exit.
echo.
echo  4. Once Windows boots back up, run this setup.bat script again!
echo =================================================================
echo.
pause
exit /b

:WSLInstall
echo [i] WSL is missing. Launching automated headless installation...
wsl --install --no-distribution
if %errorLevel% equ 0 (
    echo.
    echo =============================================================
    echo  [^+] WSL2 has been successfully configured!
    echo =============================================================
    echo  [!] CRITICAL: A system reboot is required to enable the hypervisor.
    echo.
    echo  Please RESTART your computer, then run this setup.bat again.
    echo =============================================================
    echo.
    pause
    exit /b
) else (
    goto :WSLInstallFailed
)

:WSLInstallFailed
echo.
echo =============================================================
echo  ERROR: Automated WSL installation failed.
echo =============================================================
echo  Please install the required Windows features manually:
echo.
echo  1. Open PowerShell as Administrator and run these commands:
echo.
echo     dism.exe /online /enable-feature /featurename:Microsoft-Windows-Subsystem-Linux /all /norestart
echo     dism.exe /online /enable-feature /featurename:VirtualMachinePlatform /all /norestart
echo.
echo  2. Restart your computer.
echo  3. Run this setup.bat again to finalize the deployment.
echo =============================================================
echo.
pause
exit /b