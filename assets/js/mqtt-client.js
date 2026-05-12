/**
 * MQTT Client for Food Order System
 * Wraps Paho MQTT client for browser-based MQTT over WebSocket
 * 
 * Usage:
 *   const mqtt = new FoodOrderMqtt({
 *     onMessage: (topic, data) => { ... },
 *     onConnected: () => { ... }
 *   });
 *   mqtt.connect();
 *   mqtt.subscribe('foodorder/kitchen/orders');
 */

class FoodOrderMqtt {
    constructor(options = {}) {
        this.options = {
            wsHost: options.wsHost || (window.location.hostname || 'localhost'),
            wsPort: options.wsPort || 8080,
            wsPath: options.wsPath || '/mqtt',
            clientId: 'foodorder-' + Math.random().toString(16).substr(2, 8),
            keepAlive: 60,
            timeout: 30,
            ...options
        };

        this.client = null;
        this.connected = false;
        this.subscriptions = new Map();
        this.reconnectAttempts = 0;
        this.maxReconnectAttempts = 10;
        this.reconnectDelay = 3000;
        this.onMessage = options.onMessage || null;
        this.onConnected = options.onConnected || null;
        this.onDisconnected = options.onDisconnected || null;
        this.onError = options.onError || null;
        this.debug = options.debug || false;
    }

    _getPahoClientConstructor() {
        if (typeof Paho === 'undefined') {
            return null;
        }

        if (Paho.MQTT && typeof Paho.MQTT.Client === 'function') {
            return Paho.MQTT.Client;
        }

        if (typeof Paho.Client === 'function') {
            return Paho.Client;
        }

        return null;
    }

    _getPahoMessageConstructor() {
        if (typeof Paho === 'undefined') {
            return null;
        }

        if (Paho.MQTT && typeof Paho.MQTT.Message === 'function') {
            return Paho.MQTT.Message;
        }

        if (typeof Paho.Message === 'function') {
            return Paho.Message;
        }

        return null;
    }

    _topicMatches(subscriptionFilter, topic) {
        if (subscriptionFilter === topic) {
            return true;
        }

        const filterLevels = subscriptionFilter.split('/');
        const topicLevels = topic.split('/');

        for (let i = 0; i < filterLevels.length; i++) {
            const filterLevel = filterLevels[i];
            const topicLevel = topicLevels[i];

            if (filterLevel === '#') {
                return i === filterLevels.length - 1;
            }

            if (topicLevel === undefined) {
                return false;
            }

            if (filterLevel === '+') {
                continue;
            }

            if (filterLevel !== topicLevel) {
                return false;
            }
        }

        return filterLevels.length === topicLevels.length;
    }

    connect() {
        try {
            const ClientCtor = this._getPahoClientConstructor();
            if (!ClientCtor) {
                const error = new Error('Paho MQTT library is not loaded. Check the CDN script or install a local copy.');
                if (this.debug) console.error('[MQTT] ' + error.message);
                if (this.onError) this.onError(error);
                return false;
            }

            const protocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
            const host = this.options.wsHost;
            const port = this.options.wsPort;
            const path = this.options.wsPath;

            const brokerUrl = `${protocol}//${host}:${port}${path}`;

            if (this.debug) console.log('[MQTT] Connecting to:', brokerUrl);

            this.client = new ClientCtor(host, port, path, this.options.clientId);

            this.client.onConnectionLost = (responseObject) => {
                this.connected = false;
                if (this.debug) console.log('[MQTT] Connection lost:', responseObject.errorMessage);
                this._attemptReconnect();
                if (this.onDisconnected) this.onDisconnected(responseObject);
            };

            this.client.onMessageArrived = (message) => {
                try {
                    const topic = message.destinationName;
                    const payload = message.payloadString;
                    let data;
                    try {
                        data = JSON.parse(payload);
                    } catch (e) {
                        data = payload;
                    }

                    // Call global message handler
                    if (this.onMessage) {
                        this.onMessage(topic, data);
                    }

                    // Call any matching topic handlers, including MQTT wildcards.
                    for (const [subscription, handler] of this.subscriptions.entries()) {
                        if (this._topicMatches(subscription, topic)) {
                            handler(topic, data);
                        }
                    }
                } catch (e) {
                    if (this.debug) console.error('[MQTT] Message handling error:', e);
                }
            };

            const connectOptions = {
                onSuccess: () => {
                    this.connected = true;
                    this.reconnectAttempts = 0;
                    if (this.debug) console.log('[MQTT] Connected successfully');
                    if (this.onConnected) this.onConnected();
                },
                onFailure: (error) => {
                    this.connected = false;
                    if (this.debug) console.error('[MQTT] Connection failed:', error.errorMessage);
                    if (this.onError) this.onError(error);
                    this._attemptReconnect();
                },
                keepAliveInterval: this.options.keepAlive,
                timeout: this.options.timeout,
                useSSL: protocol === 'wss:',
                cleanSession: true
            };

            // Add credentials if configured
            const mqttUser = this.options.username || '';
            const mqttPass = this.options.password || '';
            if (mqttUser && mqttPass) {
                connectOptions.userName = mqttUser;
                connectOptions.password = mqttPass;
            }

            this.client.connect(connectOptions);
            return true;
        } catch (e) {
            if (this.debug) console.error('[MQTT] Connection error:', e);
            if (this.onError) this.onError(e);
            this._attemptReconnect();
            return false;
        }
    }

    subscribe(topic, callback, qos = 1) {
        if (!this.client || !this.connected) {
            if (this.debug) console.warn('[MQTT] Cannot subscribe - not connected');
            return false;
        }

        try {
            this.client.subscribe(topic, {
                qos: qos,
                onSuccess: () => {
                    if (this.debug) console.log('[MQTT] Subscribed to:', topic);
                },
                onFailure: (error) => {
                    if (this.debug) console.error('[MQTT] Subscribe failed:', topic, error);
                }
            });

            this.subscriptions.set(topic, callback);
            return true;
        } catch (e) {
            if (this.debug) console.error('[MQTT] Subscribe error:', e);
            return false;
        }
    }

    unsubscribe(topic) {
        if (!this.client || !this.connected) return false;

        try {
            this.client.unsubscribe(topic);
            this.subscriptions.delete(topic);
            if (this.debug) console.log('[MQTT] Unsubscribed from:', topic);
            return true;
        } catch (e) {
            if (this.debug) console.error('[MQTT] Unsubscribe error:', e);
            return false;
        }
    }

    publish(topic, message, qos = 1, retained = false) {
        if (!this.client || !this.connected) return false;

        try {
            const MessageCtor = this._getPahoMessageConstructor();
            if (!MessageCtor) {
                throw new Error('Paho MQTT message class is not available.');
            }
            const mqttMessage = new MessageCtor(message);
            mqttMessage.destinationName = topic;
            mqttMessage.qos = qos;
            mqttMessage.retained = retained;
            this.client.send(mqttMessage);
            return true;
        } catch (e) {
            if (this.debug) console.error('[MQTT] Publish error:', e);
            return false;
        }
    }

    disconnect() {
        if (this.client) {
            this.client.disconnect();
            this.connected = false;
        }
    }

    isConnected() {
        return this.connected;
    }

    getSubscriptions() {
        return Array.from(this.subscriptions.keys());
    }

    _attemptReconnect() {
        if (this.reconnectAttempts >= this.maxReconnectAttempts) {
            if (this.debug) console.log('[MQTT] Max reconnect attempts reached');
            return;
        }

        this.reconnectAttempts++;
        const delay = this.reconnectDelay * Math.min(this.reconnectAttempts, 5);

        if (this.debug) console.log(`[MQTT] Reconnecting in ${delay}ms (attempt ${this.reconnectAttempts}/${this.maxReconnectAttempts})`);

        setTimeout(() => {
            this.connect();
        }, delay);
    }
}

// Global MQTT instances for different roles
let mqttClient = null;

function initMqttClient(options = {}) {
    mqttClient = new FoodOrderMqtt(options);
    return mqttClient;
}

function getMqttClient() {
    return mqttClient;
}

// Detect the base path for AJAX
const MQTT_DEFAULT_WS_PORT = 8080;
const MQTT_DEFAULT_WS_HOST = window.location.hostname || 'localhost';
