# Installation på Raspberry Pi OS

Installationen er lavet til en opdateret Raspberry Pi OS baseret på Debian Bookworm eller Trixie. Der bruges kun distributionens egne APT-pakker; Composer, Docker, Python-daemons og eksterne PHP-pakker indgår ikke.

## Samlet prerequisite-kommando

```bash
sudo apt update
sudo apt install -y \
  apache2 libapache2-mod-php \
  php-cli php-common php-curl php-mbstring php-mysql \
  mariadb-server mariadb-client \
  rsync logrotate ca-certificates curl
```

Det fulde installationsscript udfører også denne kommando, så prerequisite-trinnet behøver ikke køres separat.

## Deploy

Kopiér repositoryet til Raspberry Pi'en, gå ind i projektmappen, og kør:

```bash
chmod +x scripts/install-raspberry-pi.sh
sudo ./scripts/install-raspberry-pi.sh
```

Scriptet:

- installerer og starter Apache, PHP og MariaDB;
- opretter database og et tilfældigt lokalt database-password;
- installerer applikationen i `/opt/solportalen`;
- finder første `/dev/serial/by-id/...` eller bruger `/dev/ttyUSB0`;
- opretter den begrænsede worker-bruger `solportal` med `dialout`;
- holder Apache-brugeren `www-data` ude af `dialout`;
- installerer database-skemaet;
- udfører en enkelt read-only Modbus-test;
- starter den permanente device-worker og Apache-sitet.

## Kontrol

```bash
sudo systemctl status mariadb apache2 solportal-device --no-pager
sudo journalctl -u solportal-device -n 50 --no-pager
curl -s http://127.0.0.1/healthz
hostname -I
```

Åbn derefter `http://PI-ADRESSE/` fra en browser på lokalnettet. Live API'et er `http://PI-ADRESSE/api/v1/state`.

Ved seriefejl kontrolleres den installerede sti:

```bash
grep '^SERIAL_DEVICE=' /opt/solportalen/.env
ls -l /dev/serial/by-id/ /dev/ttyUSB0 2>/dev/null
sudo -u solportal /opt/solportalen/bin/solportal modbus:read
```

## Særlig konfiguration

- Growatt-porten skal fortsat stå i `VPP`.
- Modbus bruger 9600 baud, 8N1 og slave-ID 1.
- Worker-processen er den eneste proces med serieportadgang.
- Apache lytter indledningsvis på HTTP port 80 til lokal commissioning. Eksponér ikke portalen direkte på internettet.
- MariaDB bruger Unix socket og er ikke eksponeret på netværket.
- `WRITES_ENABLED=false` må ikke ændres under read-only commissioning.
