#!/bin/sh
set -eu
if [ "$(id -u)" -ne 0 ]; then echo "Kør som root." >&2; exit 1; fi
apt-get update
apt-get install -y apache2 libapache2-mod-php php-cli php-curl php-mbstring php-mysql mariadb-client mosquitto-clients
id solportal >/dev/null 2>&1 || useradd --system --home /opt/solportalen --shell /usr/sbin/nologin solportal
a2enmod ssl headers rewrite
echo "Pakker installeret. Følg docs/INSTALL_GENERIC.md; databasen og TLS kræver lokale valg."
