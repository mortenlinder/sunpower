# Kontrolleret Growatt write-test

Testkommandoen er bevidst kun til lokal commissioning. Den kræver to miljølåse og en eksplicit bekræftelse. Den gemmer baseline, anvender en begrænset testmode, verificerer FC06-ekko og FC03-readback, læser aktuelt priority-register 118 og gendanner derefter hele baselinekonfigurationen.

## Sikkerhedsramme

- Grid First testes med højst 20 % afladeeffekt og mindst 30 % stop-SOC.
- Battery First testes med højst 20 % ladeeffekt og AC Charge slået fra.
- Testvarighed er mindst 10 og højst 120 sekunder.
- Load First opnås ved at deaktivere alle Grid First- og Battery First-enable-registre samt AC Charge.
- Kun register 1070, 1071, 1080, 1081, 1082, 1085, 1088, 1090, 1091, 1092, 1100, 1101, 1102, 1105 og 1108 er på whitelisten.
- Registre, der allerede har den ønskede værdi, skrives ikke igen. Efter en FC06-write forsøges FC03-readback op til otte gange med kort forsinkelse.

## Forberedelse

Stop den permanente worker, så kun testprocessen ejer serieporten:

```sh
sudo systemctl stop solportal-device
sudo sed -i 's/^WRITES_ENABLED=.*/WRITES_ENABLED=true/' /opt/solportalen/.env
sudo sed -i 's/^COMMISSIONING_WRITES_ENABLED=.*/COMMISSIONING_WRITES_ENABLED=true/' /opt/solportalen/.env
```

## Tests

```sh
sudo -u solportal php /opt/solportalen/bin/solportal commissioning:mode-test load_first 20 --confirm=LOCAL_MODBUS_WRITE_TEST
sudo -u solportal php /opt/solportalen/bin/solportal commissioning:mode-test grid_first 30 --confirm=LOCAL_MODBUS_WRITE_TEST
sudo -u solportal php /opt/solportalen/bin/solportal commissioning:mode-test battery_first 30 --confirm=LOCAL_MODBUS_WRITE_TEST
```

Hver kommando skal slutte med `rollback_verified: true`. Test én mode ad gangen og kontroller inverter/display og energiflow under testen.
Undlad at lukke terminalen under testen. SIGINT og SIGTERM forsøges håndteret med automatisk rollback, men strømsvigt eller `kill -9` kan ikke håndteres af processen.

## Afslut commissioning

```sh
sudo sed -i 's/^COMMISSIONING_WRITES_ENABLED=.*/COMMISSIONING_WRITES_ENABLED=false/' /opt/solportalen/.env
sudo sed -i 's/^WRITES_ENABLED=.*/WRITES_ENABLED=false/' /opt/solportalen/.env
sudo systemctl start solportal-device
```

Hvis den normale konfiguration ikke er genetableret, kan Load First sættes eksplicit, mens miljølåsene stadig er aktive:

```sh
sudo -u solportal php /opt/solportalen/bin/solportal commissioning:load-first --confirm=LOCAL_MODBUS_WRITE_TEST
```

Et tidligere gemt commissioning-snapshot kan gendannes og verificeres med:

```sh
sudo -u solportal php /opt/solportalen/bin/solportal commissioning:restore-snapshot --confirm=LOCAL_MODBUS_WRITE_TEST
```
