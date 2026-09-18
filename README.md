# Solportalen

**Lokal energi. Fuld kontrol.** En local-first PHP 8.2-portal til aflæsning, historik og sikker planlægning af Growatt-batterianlæg via Modbus RTU.

Projektet bruger ingen Composer, frameworks, Node-runtime eller CDN'er. Apache er den understøttede webserver. Nye Raspberry Pi-installationer starter read-only; afprøvede anlæg kan anvende godkendte planer og automatisk planlægning med lokale write-rettigheder og readback.

## Offentlig portal og mobiladgang

`portal/` er en separat multi-user-applikation til `solpanel.linder.dk`: e-mailbekræftelse, login/nulstilling, sikker engangsparring, privat live-overblik, historik og tidsbegrænsede ejerkommandoer. Pi'en beholder lokal styring og forbinder udgående over HTTPS. Der åbnes ingen porte i hjemmerouteren. Offentlig produktionsdeling er frivillig og grupperet; individuelle husdata offentliggøres ikke.

Se [portaldeployment og sikkerhedsgrænser](docs/public-portal-deployment.md). **Kun `portal/public` må være offentlig document root.** Den lokale router er ikke beregnet til direkte internetadgang. Downloadpakker bygges med en eksplicit filliste, så credentials, captures og lokal historik ikke medtages.

## Hurtig start på Linux

```bash
chmod +x scripts/install-raspberry-pi.sh
sudo ./scripts/install-raspberry-pi.sh
```

Scriptet installerer Apache, PHP og MariaDB, opretter database og systembruger, udfører en live read-only Modbus-test og starter dashboardet. Se `docs/INSTALL_RASPBERRY_PI.md`.

## Arkitektur og sikkerhed

Webprocessen viser data og opretter kun højniveaukommandoer. En separat `solportal`-worker ejer serieporten. Profilfiler afgør alle registre og capabilities. Den tomme Growatt-template kan ikke skrive; serienummeret bruges kun som installationsnote. Writes kræver verificeret profil, commissioning-baseline, eksplicit global aktivering og readback.

Modbus RTU bruger 9600 8N1 og slave-ID 1 på den afprøvede installation. Konkrete registerprofiler og sikkerhedstilstand skal stadig kontrolleres ved hver ny installation. Se [commissioning](docs/COMMISSIONING.md) og [hardwarestatus](docs/HARDWARE.md); ældre commissioning-noter er historiske, ikke bevis for kompatibilitet med andre anlæg.

## Prognoser og intelligent styring

`solportal-forecast.timer` opdaterer hvert kvarter vejrprognosen og 15-minutters priser. Solportalen kombinerer spotpris med konfigurerede tariffer, afgift, tillæg og moms. Nye installationer er rådgivende/read-only. Et afprøvet anlæg med aktiverede writes kan anvende en godkendt plan eller aktivere automatisk styring i panelets kalender. Lokal standardmode bruges uden en gyldig plan.

Leverandørvagten henter højst én gang ugentligt den offentlige produktfil for det konfigurerede netområde fra elpris.dk. Den sammenligner kun den del af regningen, som leverandørvalget kan ændre, og filtrerer produkter fra, når deres forbrugsgrænse er lavere end husstandens forventede årsforbrug. Kør et tvunget opslag med `php bin/solportal supplier:refresh`, og se resultatet på `/suppliers`. Resultatet er vejledende; solcelle-producentaftaler og aktuelle vilkår skal kontrolleres før et leverandørskifte.

Device-workeren lærer samtidig et lokalt basisforbrug. Et stabilt belastningsspring over `EV_DETECT_W` registreres som en sandsynlig elbilopladning med energi og confidence, men korte spidser ignoreres. Standardværdier for lokation, solcelleeffekt, tariffer og detektion står i `.env.example` og skal tilpasses den konkrete elaftale og installation.

Efter hver forecast-opdatering genererer optimizer en plan for tilgængelige prisintervaller op til 48 timer. Den bruger batteriets faktiske SOC, effektgrænser, reserve, virkningsgrad og slid samt forventet sol og en lokalt lært forbrugsprofil. Manuel godkendelse og anvendelse er to særskilte trin. Automatisk styring genvurderer også SOC/forbrug under en uændret prishorisont. Plananvendelse og midlertidige fjernkommandoer går gennem den samme serieportsejer og auditeres med readback.
