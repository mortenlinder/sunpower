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

## Prognoser og intelligent shadow mode

`solportal-forecast.timer` opdaterer hvert kvarter vejrprognosen for Værløse fra Yr og 15-minutters DK2-priser fra Energinets aktuelle `DayAheadPrices`-datasæt. Solportalen kombinerer spotpris med konfigurerbare Radius-tariffer, afgift, tillæg og moms og viser billige ladevinduer i shadow mode. Planen er kun rådgivende; `WRITES_ENABLED=false` gælder fortsat.

Leverandørvagten henter højst én gang ugentligt den offentlige produktfil for det konfigurerede netområde fra elpris.dk. Den sammenligner kun den del af regningen, som leverandørvalget kan ændre, og filtrerer produkter fra, når deres forbrugsgrænse er lavere end husstandens forventede årsforbrug. Kør et tvunget opslag med `php bin/solportal supplier:refresh`, og se resultatet på `/suppliers`. Resultatet er vejledende; solcelle-producentaftaler og aktuelle vilkår skal kontrolleres før et leverandørskifte.

Device-workeren lærer samtidig et lokalt basisforbrug. Et stabilt belastningsspring over `EV_DETECT_W` registreres som en sandsynlig elbilopladning med energi og confidence, men korte spidser ignoreres. Standardværdier for lokation, solcelleeffekt, tariffer og detektion står i `.env.example` og skal tilpasses den konkrete elaftale og installation.

Efter hver forecast-opdatering genererer den dynamiske optimizer en plan for alle tilgængelige prisintervaller op til 48 timer. Den simulerer batteriets SOC, effektgrænser, reserve, virkningsgrad og slid mod forventet sol og en lokalt lært time-/ugedagsprofil. Dashboardet sammenligner planen med drift uden aktiv batteriplan og viser forventet besparelse. En plan kan godkendes manuelt som `approved_shadow`; godkendelsen auditeres, men opretter ingen kommando og udfører ingen Modbus-write.
