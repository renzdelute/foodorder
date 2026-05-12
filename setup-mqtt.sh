#!/bin/bash
# ==============================================================================
# MQTT BROKER SETUP SCRIPT (Mosquitto)
# For Food Order System
#
# This script sets up Mosquitto MQTT broker with WebSocket support
# ==============================================================================

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

echo "=========================================="
echo "  Food Order System - MQTT Broker Setup"
echo "=========================================="
echo ""

# Configuration
MQTT_PORT=1883
WS_PORT=8080
MQTT_USER="foodorder"
MQTT_PASS="foodorder2026"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${GREEN}[1/5] Installing Mosquitto MQTT Broker...${NC}"

if command -v apt-get &> /dev/null; then
    sudo apt-get update
    sudo apt-get install -y mosquitto mosquitto-clients
elif command -v yum &> /dev/null; then
    sudo yum install -y mosquitto
elif command -v brew &> /dev/null; then
    brew install mosquitto
else
    echo -e "${RED}Could not find package manager. Please install Mosquitto manually.${NC}"
    echo "Visit: https://mosquitto.org/download/"
    exit 1
fi

echo -e "${GREEN}[2/5] Configuring Mosquitto with WebSocket support...${NC}"

# Create Mosquitto configuration
MOSQ_CONF=$(which mosquitto)
MOSQ_DIR=$(dirname "$MOSQ_CONF")
CONF_DIR="/etc/mosquitto"

# Create custom config
cat > /tmp/mosquitto-foodorder.conf << EOF
# Mosquitto MQTT Broker Configuration for Food Order System

# Standard MQTT listener
listener ${MQTT_PORT}
protocol mqtt

# WebSocket listener for browser connections
listener ${WS_PORT}
protocol websockets

# Allow anonymous connections (set to false for production)
allow_anonymous true

# Password file (if using authentication)
# password_file ${CONF_DIR}/mosquitto.passwd

# Persistence
persistence true
persistence_location /var/lib/mosquitto/
persistence_file mosquitto.db

# Log settings
log_dest syslog
log_dest stdout
log_dest topic
log_type error
log_type warning
log_type notice
log_type information

# Connection settings
connection_messages true
log_timestamp true

# WebSockets configuration
websockets_log_level 1023
EOF

echo -e "${GREEN}[3/5] Setting up authentication...${NC}"

# Create password file
# sudo touch ${CONF_DIR}/mosquitto.passwd
# sudo mosquitto_passwd -b ${CONF_DIR}/mosquitto.passwd ${MQTT_USER} ${MQTT_PASS}

# Determine config path
if [ -f "/etc/mosquitto/mosquitto.conf" ]; then
    # Backup original config
    sudo cp /etc/mosquitto/mosquitto.conf /etc/mosquitto/mosquitto.conf.backup 2>/dev/null || true
    
    # Append our config or replace
    sudo cp /tmp/mosquitto-foodorder.conf /etc/mosquitto/conf.d/foodorder.conf 2>/dev/null || \
    sudo cp /tmp/mosquitto-foodorder.conf /etc/mosquitto/mosquitto.conf
elif [ -d "/etc/mosquitto" ]; then
    sudo cp /tmp/mosquitto-foodorder.conf /etc/mosquitto/mosquitto.conf
else
    # Use local config for development
    echo "Using local configuration..."
    cp /tmp/mosquitto-foodorder.conf "$SCRIPT_DIR/mosquitto.conf"
    CONF_DIR="$SCRIPT_DIR"
fi

echo -e "${GREEN}[4/5] Creating PHP environment file...${NC}"

# Create .env file for PHP configuration
cat > "$SCRIPT_DIR/.env" << EOF
# MQTT Broker Configuration
MQTT_HOST=127.0.0.1
MQTT_PORT=${MQTT_PORT}
MQTT_USERNAME=${MQTT_USER}
MQTT_PASSWORD=${MQTT_PASS}
WS_MQTT_HOST=127.0.0.1
WS_MQTT_PORT=${WS_PORT}
EOF

echo -e "${GREEN}[5/5] Starting Mosquitto broker...${NC}"

# Stop existing instance
sudo pkill mosquitto 2>/dev/null || true
sleep 1

# Try different methods to start
if command -v systemctl &> /dev/null; then
    sudo systemctl enable mosquitto 2>/dev/null || true
    sudo systemctl start mosquitto 2>/dev/null || true
elif command -v service &> /dev/null; then
    sudo service mosquitto start 2>/dev/null || true
else
    # Direct start
    mosquitto -c /tmp/mosquitto-foodorder.conf -d 2>/dev/null || \
    mosquitto -c /tmp/mosquitto-foodorder.conf &
fi

sleep 2

# Verify installation
echo ""
echo -e "${YELLOW}Testing Mosquitto installation...${NC}"

if pgrep mosquitto > /dev/null; then
    echo -e "${GREEN}✓ Mosquitto is running${NC}"
else
    echo -e "${RED}✗ Mosquitto is not running. Please start manually:${NC}"
    echo "  mosquitto -c /tmp/mosquitto-foodorder.conf -v"
fi

# Test MQTT connection
echo ""
echo -e "${YELLOW}Testing MQTT connection...${NC}"

if command -v mosquitto_pub &> /dev/null; then
    mosquitto_pub -h 127.0.0.1 -p ${MQTT_PORT} -t "foodorder/test" -m "hello" -q 1 2>/dev/null && \
    echo -e "${GREEN}✓ MQTT publish test successful${NC}" || \
    echo -e "${YELLOW}⚠ Could not test MQTT publish${NC}"
fi

echo ""
echo "=========================================="
echo -e "${GREEN}Setup Complete!${NC}"
echo "=========================================="
echo ""
echo "Broker Address:  127.0.0.1:${MQTT_PORT}"
echo "WebSocket Port:  ${WS_PORT}"
echo ""
echo "Connect with MQTT Explorer using:"
echo "  Host: 127.0.0.1"
echo "  Port: ${MQTT_PORT} (MQTT) or ${WS_PORT} (WebSocket)"
echo ""
echo "Topics to subscribe to in MQTT Explorer:"
echo "  foodorder/kitchen/orders    - Kitchen new orders"
echo "  foodorder/kitchen/status    - Kitchen status updates"
echo "  foodorder/system/orders     - All system orders"
echo "  foodorder/customer/#        - Customer orders"
echo "  foodorder/admin/dashboard   - Admin dashboard stats"
echo "  foodorder/#                 - All topics (wildcard)"
echo ""
echo "Browser clients already connect directly to Mosquitto's WebSocket listener on ${WS_PORT}."
echo "You do NOT need ws-mqtt-bridge.php for the current UI."
echo ""
