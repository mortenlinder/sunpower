# Solportalen

**Lokal energi. Fuld kontrol.** En local-first PHP 8.2-portal til aflæsning, historik og sikker planlægning af Growatt-batterianlæg via Modbus RTU.

Projektet bruger ingen Composer, frameworks, Node-runtime, CDN'er eller cloudkontrol. Apache er den eneste understøttede webserver. Raspberry Pi-deploymenten bruger den verificerede read-only RS485-forbindelse; alle Modbus-writes er deaktiveret.

## Hurtig start på Linux

```bash
chmod +x scripts/install-raspberry-pi.sh
sudo ./scripts/install-raspberry-pi.sh
```

Scriptet installerer Apache, PHP og MariaDB, opretter database og systembruger, udfører en live read-only Modbus-test og starter dashboardet. Se `docs/INSTALL_RASPBERRY_PI.md`.

## Arkitektur og sikkerhed

Webprocessen viser data og opretter kun højniveaukommandoer. En separat `solportal`-worker ejer serieporten. Profilfiler afgør alle registre og capabilities. Den tomme Growatt-template kan ikke skrive; serienummeret bruges kun som installationsnote. Writes kræver verificeret profil, commissioning-baseline, eksplicit global aktivering og readback.

Fysisk read-only Modbus RTU er verificeret med 9600 8N1, slave-ID 1 og function 04. Signalmappingen er fortsat under commissioning; writes og safe state er ikke verificeret. Se [commissioning](docs/COMMISSIONING.md) og [hardwarestatus](docs/HARDWARE.md).
