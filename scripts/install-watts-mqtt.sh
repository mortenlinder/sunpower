#!/bin/sh
set -eu

APP_DIR=/opt/solportalen
ENV_FILE=$APP_DIR/.env
DEVICE_CREDENTIALS=/home/ml/solportalen-deploy/watts-mqtt-device.txt

if [ "$(id -u)" -ne 0 ]; then
    echo "Kør scriptet som root." >&2
    exit 1
fi

apt-get update
DEBIAN_FRONTEND=noninteractive apt-get install -y mosquitto mosquitto-clients

temporary=$(mktemp "$APP_DIR/.env.mqtt.XXXXXX")
grep -v '^WATTS_MQTT_HOST=' "$ENV_FILE" \
    | grep -v '^WATTS_MQTT_PORT=' \
    | grep -v '^WATTS_MQTT_USER=' \
    | grep -v '^WATTS_MQTT_PASSWORD=' \
    | grep -v '^WATTS_MQTT_TOPIC=' > "$temporary"
printf 'WATTS_MQTT_HOST=127.0.0.1\nWATTS_MQTT_PORT=1884\nWATTS_MQTT_USER=\nWATTS_MQTT_PASSWORD=\nWATTS_MQTT_TOPIC=watts/+/measurement\n' >> "$temporary"
chown root:solportal-app "$temporary"
chmod 0640 "$temporary"
mv "$temporary" "$ENV_FILE"

device_password=$(od -An -N12 -tx1 /dev/urandom | tr -d ' \n')
mosquitto_passwd -b -c /etc/mosquitto/passwd wattslive "$device_password"
chown root:mosquitto /etc/mosquitto/passwd
chmod 0640 /etc/mosquitto/passwd

install -d -o ml -g ml -m 0700 /home/ml/solportalen-deploy
umask 077
printf 'Hostname: 192.168.1.116\nPort: 1883\nTransport: 0x00\nUsername: wattslive\nPassword: %s\nTopic: watts/SERIENUMMER/measurement\n' "$device_password" > "$DEVICE_CREDENTIALS"
chown ml:ml "$DEVICE_CREDENTIALS"

cat > /etc/mosquitto/acl.solportalen <<'ACL'
user wattslive
topic write watts/+/measurement

ACL
chown root:mosquitto /etc/mosquitto/acl.solportalen
chmod 0640 /etc/mosquitto/acl.solportalen

cat > /etc/mosquitto/conf.d/solportalen.conf <<'CONF'
per_listener_settings true
listener 1883 0.0.0.0
allow_anonymous false
password_file /etc/mosquitto/passwd
acl_file /etc/mosquitto/acl.solportalen
persistence true

listener 1884 127.0.0.1
allow_anonymous true
CONF

install -o root -g root -m 0644 "$APP_DIR/systemd/solportal-watts-mqtt.service" /etc/systemd/system/solportal-watts-mqtt.service
systemctl daemon-reload
systemctl enable --now mosquitto solportal-watts-mqtt.service

echo "Watts MQTT broker og worker er installeret."
echo "Enhedens indstillinger ligger i $DEVICE_CREDENTIALS"
