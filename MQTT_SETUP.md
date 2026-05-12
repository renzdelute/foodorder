# MQTT Setup & Explorer Guide
# Food Order System - MQTT Integration

## Prerequisites

1. **Mosquitto MQTT Broker** must be installed and running
2. **PHP MQTT Client** is already installed via Composer (`php-mqtt/client`)
3. **Node.js** is optional and only needed if you experiment with the standalone WebSocket bridge
4. The browser MQTT client library is bundled locally in `assets/js/vendor/paho-mqtt.min.js`

---

## Step 1: Install Mosquitto

### Option A: Using Docker (Recommended)
```bash
docker run -d --name mosquitto \
  -p 1883:1883 \
  -p 8080:8080 \
  -v $(pwd)/mosquitto/config:/mosquitto/config \
  -v $(pwd)/mosquitto/data:/mosquitto/data \
  -v $(pwd)/mosquitto/log:/mosquitto/log \
  eclipse-mosquitto:2
```

Create `mosquitto/config/mosquitto.conf`:
```
listener 1883
protocol mqtt

listener 8080
protocol websockets

allow_anonymous true

persistence true
persistence_location /mosquitto/data/
persistence_file mosquitto.db

log_dest stdout
log_type error
log_type warning
log_type notice
log_type information
```

### Option B: Direct Install
- **Windows**: Download from https://mosquitto.org/download/ or `choco install mosquitto`
- **Linux**: `sudo apt install mosquitto mosquitto-clients`
- **Mac**: `brew install mosquitto`

### Start Mosquitto
```bash
# Linux/Mac
mosquitto -c mosquitto.conf -v

# Windows
mosquitto.exe -c mosquitto-config\mosquitto.conf -v

# Or as service (Linux)
sudo systemctl start mosquitto
sudo systemctl enable mosquitto
```

### Test Broker is Running
```bash
# Terminal 1 - Subscribe to test topic
mosquitto_sub -h 127.0.0.1 -p 1883 -t "foodorder/#" -v

# Terminal 2 - Publish test message
mosquitto_pub -h 127.0.0.1 -p 1883 -t "foodorder/test" -m "Hello MQTT"
```

---

## Step 2: Configure PHP Environment

Create/update `.env` file in project root:
```env
MQTT_HOST=127.0.0.1
MQTT_PORT=1883
MQTT_USERNAME=
MQTT_PASSWORD=
WS_MQTT_HOST=127.0.0.1
WS_MQTT_PORT=8080
```

---

## Step 3: Connect the Browser UI

The current dashboard, kitchen, and customer pages connect directly to Mosquitto over MQTT WebSocket on port `8080`.

Do not start `ws-mqtt-bridge.php` for the shipped UI. It is a standalone experimental bridge and is not used by the front-end MQTT client in this repo.

---

## Step 4: Using MQTT Explorer

### Download & Install
- **MQTT Explorer**: https://mqtt-explorer.com/ (desktop app)
- Or use any MQTT client: MQTT.fx, MQTTX, etc.

### Connection Settings in MQTT Explorer
```
Name:        Food Order System
Host:        127.0.0.1
Port:        1883
Client ID:   mqtt-explorer-client
Protocol:    MQTT 3.1.1
Clean Session: Yes
```

### Subscribe to Topics (for monitoring)

After connecting, subscribe to these topics:

| Topic | Type | Purpose |
|-------|------|---------|
| `foodorder/#` | Wildcard | ALL messages |
| `foodorder/system/orders` | Subscribe | All order events |
| `foodorder/kitchen/orders` | Subscribe | New orders for kitchen |
| `foodorder/kitchen/status` | Subscribe | Kitchen stats updates |
| `foodorder/admin/dashboard` | Subscribe | Dashboard statistics |
| `foodorder/orders/ORD-XXXX` | Subscribe | Specific order updates |
| `foodorder/customer/{user_id}/orders` | Subscribe | Customer-specific orders |

### Publish to Topics (for testing)

You can also publish messages to test the system:

**Publish a new order:**
```
Topic:   foodorder/kitchen/orders
QoS:     1
Payload: {
  "event": "new_order",
  "order_code": "ORD-TEST01",
  "customer": "Test Customer",
  "items": [
    {"name": "Burger", "quantity": 2, "price": 150.00},
    {"name": "Fries", "quantity": 1, "price": 75.00}
  ],
  "total_amount": 375.00,
  "timestamp": "2026-05-11T22:00:00+08:00"
}
```

**Publish a status change:**
```
Topic:   foodorder/orders/ORD-TEST01
QoS:     1
Payload: {
  "event": "status_change",
  "order_code": "ORD-TEST01",
  "old_status": "pending",
  "new_status": "preparing",
  "customer": "Test Customer",
  "timestamp": "2026-05-11T22:01:00+08:00"
}
```

**Publish system order event:**
```
Topic:   foodorder/system/orders
QoS:     1
Payload: {
  "order_code": "ORD-TEST01",
  "order_id": 999,
  "status": "ready",
  "customer": "Test Customer",
  "user_id": 5,
  "timestamp": "2026-05-11T22:02:00+08:00"
}
```

**Publish admin dashboard stats:**
```
Topic:   foodorder/admin/dashboard
QoS:     1
Payload: {
  "status": "ready",
  "counts": {
    "pending": 3,
    "preparing": 2,
    "ready": 5,
    "completed": 42
  },
  "timestamp": "2026-05-11T22:03:00+08:00"
}
```

---

## Topic Structure Summary

```
foodorder/
├── system/
│   └── orders              ← All system-wide order events
├── kitchen/
│   ├── orders              ← New orders (kitchen staff subscribes)
│   └── status              ← Kitchen queue statistics
├── customer/
│   └── {user_id}/          ← Per-customer order updates
│       └── orders
├── orders/
│   └── {order_code}        ← Per-order status tracking
├── admin/
│   ├── dashboard            ← Dashboard statistics
│   └── events              ← Admin system events
└── test                     ← Test topic
```

### Role Mapping

| Role | MQTT Role | Subscribes To | Publishes To |
|------|-----------|---------------|--------------|
| **Kitchen Staff** | Publisher | `foodorder/kitchen/orders` (for notifications) | `foodorder/kitchen/orders`, `foodorder/orders/{code}` |
| **Customer** | Subscriber | `foodorder/customer/{id}/orders`, `foodorder/orders/{code}` | - |
| **Admin** | Both | `foodorder/#` (monitoring) | `foodorder/admin/dashboard`, `foodorder/admin/events` |

---

## Architecture Overview

The browser UI in this repo connects directly to Mosquitto's WebSocket listener using the bundled local Paho client. The bridge shown below is optional and is not used by the shipped dashboard, kitchen, or customer pages.

```
┌─────────────┐     ┌──────────────────┐     ┌─────────────────┐
│  PHP App    │────▶│  Mosquitto Broker │────▶│  MQTT Explorer  │
│             │     │  (Port 1883)     │     │  Desktop App    │
│  - Publish  │     │                  │     │  (Test/Monitor) │
│  - Subscribe│     └──────────────────┘     └─────────────────┘
└─────────────┘              │
       │                    │ WebSocket Bridge
       │              ┌─────┴──────┐
       └──────────────▶│  Port 8080 │──── Browser Clients
                       └────────────┘
                          │
                    ┌─────┴──────┐
                    │  Kitchen   │
                    │  Staff UI  │
                    └────────────┘

                    ┌─────┴──────┐
                    │  Customer  │
                    │  UI        │
                    └────────────┘

                    ┌─────┴──────┐
                    │  Admin     │
                    │  Dashboard │
                    └────────────┘
```

---

## API Endpoints

| Endpoint | Method | Description | Auth |
|----------|--------|-------------|------|
| `ajax/mqtt_api.php?action=get_mqtt_status` | GET | Check MQTT broker status | Admin |
| `ajax/mqtt_api.php?action=get_topics` | GET | List all MQTT topics | Admin |
| `ajax/mqtt_api.php?action=get_live_events` | GET | Get recent order events | Admin |
| `ajax/mqtt_api.php?action=test_connection` | GET | Test MQTT connectivity | Admin |
| `ajax/mqtt_api.php?action=subscribe_info` | GET | Get WebSocket connection info | Public |

---

## PHP Usage Examples

### Publishing from any PHP file:
```php
require_once __DIR__ . '/config/MqttClient.php';

// Create service and connect
$mqtt = new MqttService('127.0.0.1', 1883, 'my-publisher');
if ($mqtt->connect()) {
    // Publish order event
    $mqtt->publishOrderEvent('ORD-1001', 'pending', 'John Doe', 42, 1);
    
    // Publish new order notification
    $mqtt->publishNewOrder('ORD-1001', 'John Doe', 
        [['name' => 'Burger', 'quantity' => 2, 'price' => 150.00]], 
        300.00, 42);
    
    // Publish status change
    $mqtt->publishStatusChange('ORD-1001', 'pending', 'preparing', 'John Doe', 42);
    
    // Update kitchen status
    $mqtt->publishKitchenStatus('preparing', 3, 2, 5, 42);
    
    $mqtt->disconnect();
}

// Quick publish using legacy class
MqttPublisher::publishOrder('ORD-1001', 'pending', 'John Doe');
```

### Subscribing (CLI scripts):
```php
$mqtt = new MqttService();
$mqtt->connect();

$mqtt->subscribeKitchenOrders(function($topic, $data) {
    echo "New order received: " . $data['order_code'] . "\n";
});

$mqtt->subscribeSystemOrders(function($topic, $data) {
    echo "System event: " . json_encode($data) . "\n";
});

// Keep listening
while (true) {
    $mqtt->loop();
    usleep(100000); // 100ms
}
```
