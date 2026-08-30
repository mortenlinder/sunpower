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

# En tidligere installation kan allerede have device-workeren kørende. Stop den
# før filer udskiftes og før den eksklusive read-only serieportstest køres.
systemctl stop solportal-device.service 2>/dev/null || true

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
LOCATION_NAME=Værløse
LOCATION_LAT=55.7833
LOCATION_LON=12.3833
PV_PEAK_W=6000
GRID_TARIFF_LOW_DKK=0.1062
GRID_TARIFF_HIGH_DKK=0.1593
GRID_TARIFF_PEAK_DKK=0.4141
ENERGY_TAX_DKK=0.009
SUPPLIER_MARKUP_DKK=0
EV_DETECT_W=4500
DEFAULT_LOAD_W=900
BATTERY_CAPACITY_KWH=6.5
BATTERY_MIN_SOC_PCT=20
BATTERY_RESERVE_PCT=20
BATTERY_MAX_SOC_PCT=95
BATTERY_MAX_CHARGE_W=2500
BATTERY_MAX_DISCHARGE_W=2500
BATTERY_ROUND_TRIP_EFFICIENCY=0.88
BATTERY_WEAR_DKK_KWH=0.12
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
install -m 0644 "$APP_DIR/systemd/solportal-forecast.service" /etc/systemd/system/solportal-forecast.service
install -m 0644 "$APP_DIR/systemd/solportal-forecast.timer" /etc/systemd/system/solportal-forecast.timer
systemctl daemon-reload

runuser -u solportal -- php "$APP_DIR/bin/solportal" database:migrate
# En commissioning-læsning må ikke blokere resten af en softwareopdatering, hvis
# en anden lokal proces stadig ejer porten. Den permanente worker vil genstarte.
if ! runuser -u solportal -- php "$APP_DIR/bin/solportal" worker:device --once; then
    echo "ADVARSEL: Modbus-engangstesten kunne ikke køres. Kontroller portejeren efter installationen." >&2
fi
runuser -u solportal -- php "$APP_DIR/bin/solportal" scheduler:run

systemctl enable --now solportal-device.service
systemctl enable --now solportal-forecast.timer
systemctl restart apache2

echo
echo "Solportalen er installeret."
echo "Dashboard: http://$(hostname -I | awk '{print $1}')/"
echo "Serieport: ${SERIAL_DEVICE}"
echo "Status: systemctl status solportal-device --no-pager"
echo "Live log: journalctl -u solportal-device -f"
