#!/bin/sh
set -eu

if [ "$(id -u)" -ne 0 ]; then
    echo "Kør scriptet med sudo." >&2
    exit 1
fi

SOURCE_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
APP_DIR=/opt/solportalen
DB_NAME=solportalen
DB_USER=solportal
DB_PASSWORD=$(od -An -N24 -tx1 /dev/urandom | tr -d ' \n')

apt-get update
DEBIAN_FRONTEND=noninteractive apt-get install -y \
    apache2 \
    libapache2-mod-php \
    php-cli \
    php-common \
    php-curl \
    php-mbstring \
    php-mysql \
    mariadb-server \
    mariadb-client \
    git \
    rsync \
    logrotate \
    ca-certificates \
    curl

systemctl enable --now mariadb apache2

getent group solportal-app >/dev/null 2>&1 || groupadd --system solportal-app
id solportal >/dev/null 2>&1 || useradd --system --home "$APP_DIR" --shell /usr/sbin/nologin --gid solportal-app solportal
usermod -a -G dialout,solportal-app solportal
usermod -a -G solportal-app www-data

mkdir -p "$APP_DIR"
rsync -a --delete --exclude='.git/' --exclude='.env' "$SOURCE_DIR/" "$APP_DIR/"
mkdir -p "$APP_DIR/var"

mariadb --protocol=socket <<SQL
CREATE DATABASE IF NOT EXISTS ${DB_NAME} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES ON ${DB_NAME}.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL

SERIAL_DEVICE=$(find /dev/serial/by-id -maxdepth 1 -type l 2>/dev/null | head -n 1 || true)
if [ -z "$SERIAL_DEVICE" ]; then
    SERIAL_DEVICE=/dev/ttyUSB0
fi

APP_KEY=$(od -An -N32 -tx1 /dev/urandom | tr -d ' \n')
cat > "$APP_DIR/.env" <<ENV
APP_NAME=Solportalen
APP_TAGLINE="Lokal energi. Fuld kontrol."
APP_ENV=production
APP_KEY=${APP_KEY}
APP_URL=http://solportal.local
APP_TIMEZONE=Europe/Copenhagen
DB_DSN=mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=${DB_NAME};charset=utf8mb4
DB_USER=${DB_USER}
DB_PASSWORD=${DB_PASSWORD}
DEVICE_MODE=growatt
WRITES_ENABLED=false
AUTOMATION_MODE=shadow
PRICE_AREA=DK2
SERIAL_DEVICE=${SERIAL_DEVICE}
SERIAL_BAUD=9600
SERIAL_SLAVE_ID=1
MQTT_ENABLED=false
ENV

chown -R root:solportal-app "$APP_DIR"
chown solportal:solportal-app "$APP_DIR/var"
chmod 0750 "$APP_DIR" "$APP_DIR/var"
chmod 0640 "$APP_DIR/.env"
chmod 0755 "$APP_DIR/bin/solportal"

install -m 0644 "$APP_DIR/apache/solportalen.conf" /etc/apache2/sites-available/solportalen.conf
a2dissite 000-default >/dev/null 2>&1 || true
a2ensite solportalen
a2enmod headers
apache2ctl configtest

install -m 0644 "$APP_DIR/systemd/solportal-device.service" /etc/systemd/system/solportal-device.service
systemctl daemon-reload

runuser -u solportal -- php "$APP_DIR/bin/solportal" database:migrate
runuser -u solportal -- php "$APP_DIR/bin/solportal" worker:device --once

systemctl enable --now solportal-device.service
systemctl restart apache2

echo
echo "Solportalen er installeret."
echo "Dashboard: http://$(hostname -I | awk '{print $1}')/"
echo "Serieport: ${SERIAL_DEVICE}"
echo "Status: systemctl status solportal-device --no-pager"
echo "Live log: journalctl -u solportal-device -f"
