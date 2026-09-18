#!/bin/sh
# Narrow, backed-up update for an existing installation. Does not change .env.
set -eu
[ "$(id -u)" -eq 0 ] || { echo 'Run as root' >&2; exit 1; }
SOURCE=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
APP=/opt/solportalen
[ -f "$APP/bootstrap.php" ] || { echo 'Existing installation required' >&2; exit 1; }
[ "$SOURCE" != "$APP" ] || { echo 'Use a separate source checkout' >&2; exit 1; }
FILES='bin/solportal
src/Application/ManualPlanCommandProcessor.php
src/Application/RemoteModeCommandProcessor.php
src/Device/Growatt/GrowattSphControl.php
src/Device/Growatt/ModeControl.php
src/Energy/Planning/AutomaticPlanService.php
src/Repository/StateRepository.php
src/Integration/Cloud/CloudAgent.php
src/Integration/Cloud/RemoteMode.php
resources/views/dashboard.php
public/assets/js/app.js
systemd/solportal-cloud.service'
for file in $FILES; do
    test -f "$SOURCE/$file"
    case "$file" in *.php|bin/solportal) php -l "$SOURCE/$file" >/dev/null;; esac
done
BACKUP=$(mktemp -d /opt/solportalen-cloud-backup.XXXXXX)
chmod 0700 "$BACKUP"
for file in $FILES; do
    if [ -f "$APP/$file" ]; then mkdir -p "$BACKUP/$(dirname "$file")"; cp -p "$APP/$file" "$BACKUP/$file"; fi
done
restore_worker() { systemctl start solportal-device.service || true; }
trap restore_worker EXIT
systemctl stop solportal-device.service
for file in $FILES; do
    install -d -o root -g solportal-app -m 0750 "$APP/$(dirname "$file")"
    mode=0640
    case "$file" in public/*) mode=0644;; bin/solportal) mode=0755;; esac
    install -o root -g solportal-app -m "$mode" "$SOURCE/$file" "$APP/$file"
done
install -m 0644 "$SOURCE/systemd/solportal-cloud.service" /etc/systemd/system/solportal-cloud.service
systemctl daemon-reload
systemctl start solportal-device.service
systemctl enable --now solportal-cloud.service
echo "Cloud support installed. Backup: $BACKUP"
echo 'No credentials, pairing, write settings or automation settings were changed.'
systemctl is-active solportal-device.service solportal-cloud.service
