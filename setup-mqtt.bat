@echo off
REM ==============================================================================
REM MQTT Broker Setup Script for Windows
REM For Food Order System
REM ==============================================================================

echo ==========================================
echo   Food Order System - MQTT Broker Setup
echo ==========================================
echo.

set "SCRIPT_DIR=%~dp0"

REM Check if Mosquitto is installed
where mosquitto >nul 2>&1
if %ERRORLEVEL% NEQ 0 (
    echo [ERROR] Mosquitto not found in PATH.
    echo.
    echo Please install Mosquitto first:
    echo  1. Download from: https://mosquitto.org/download/
    echo  2. Or install via Chocolatey: choco install mosquitto
    echo  3. Or download Windows binaries from: https://github.com/eclipse-mosquitto/mosquitto/releases
    echo.
    echo After installation, add mosquitto to your PATH and re-run this script.
    pause
    exit /b 1
)

echo [1/4] Creating Mosquitto configuration file...

REM Create configuration directory
if not exist "%SCRIPT_DIR%mosquitto-config" mkdir "%SCRIPT_DIR%mosquitto-config"

REM Create custom config
(
    echo # Mosquitto MQTT Broker Configuration for Food Order System
    echo 
    echo # Standard MQTT listener
    echo listener 1883
    echo protocol mqtt
    echo 
    echo # WebSocket listener for browser connections
    echo listener 8080
    echo protocol websockets
    echo 
    echo # Allow anonymous connections (set to false for production)
    echo allow_anonymous true
    echo 
    echo # Persistence
    echo persistence true
    echo persistence_location %SCRIPT_DIR%mosquitto-data\
    echo persistence_file mosquitto.db
    echo 
    echo # Log settings
    echo log_dest stdout
    echo log_type error
    echo log_type warning
    echo log_type notice
    echo log_type information
    echo 
    echo # Connection settings
    echo connection_messages true
    echo log_timestamp true
) > "%SCRIPT_DIR%mosquitto-config\mosquitto.conf"

echo [2/4] Creating data directories...
if not exist "%SCRIPT_DIR%mosquitto-data" mkdir "%SCRIPT_DIR%mosquitto-data"

echo [3/4] Creating PHP environment file...

REM Create .env file for PHP configuration
if not exist "%SCRIPT_DIR%\.env" (
    (
        echo # MQTT Broker Configuration
        echo MQTT_HOST=127.0.0.1
        echo MQTT_PORT=1883
        echo MQTT_USERNAME=
        echo MQTT_PASSWORD=
        echo WS_MQTT_HOST=127.0.0.1
        echo WS_MQTT_PORT=8080
    ) > "%SCRIPT_DIR%\.env"
    echo Created .env file
) else (
    echo .env file already exists, skipping...
)

echo [4/4] Starting Mosquitto broker...
echo.

REM Check if Mosquitto is already running
tasklist /FI "IMAGENAME eq mosquitto.exe" 2>NUL | find /I /N "mosquitto.exe">NUL
if %ERRORLEVEL% EQU 0 (
    echo Mosquitto is already running.
    echo.
) else (
    echo Starting Mosquitto broker...
    start "" mosquitto -c "%SCRIPT_DIR%mosquitto-config\mosquitto.conf" -v
    timeout /t 2 >nul
)

echo ==========================================
echo.

REM Verify it's running
tasklist /FI "IMAGENAME eq mosquitto.exe" 2>NUL | find /I /N "mosquitto.exe">NUL
if %ERRORLEVEL% EQU 0 (
    echo [OK] Mosquitto is running.
) else (
    echo [WARN] Mosquitto may not be running. Try starting manually:
    echo        mosquitto -c "%SCRIPT_DIR%mosquitto-config\mosquitto.conf" -v
)

echo.
echo Test MQTT with:
echo     mosquitto_pub -h 127.0.0.1 -p 1883 -t "foodorder/test" -m "hello"
echo     mosquitto_sub -h 127.0.0.1 -p 1883 -t "foodorder/#" -v
echo.
echo Connect with MQTT Explorer:
echo     Host: 127.0.0.1
echo     Port: 1883 (MQTT) or 8080 (WebSocket)
echo.
echo Topics to subscribe to:
echo     foodorder/kitchen/orders    - Kitchen new orders
echo     foodorder/kitchen/status    - Kitchen status updates
echo     foodorder/system/orders     - All system orders
echo     foodorder/customer/#        - Customer orders
echo     foodorder/admin/dashboard   - Admin dashboard stats
echo     foodorder/#                 - All topics (wildcard)
echo.
echo Browser clients already connect directly to Mosquitto's WebSocket listener on 8080.
echo You do NOT need ws-mqtt-bridge.php for the current UI.
echo.
pause
