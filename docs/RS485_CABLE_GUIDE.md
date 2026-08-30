# Guide: kabel til lokal Modbus på Growatt SPH

## Aktuel konklusion

To uafhængige integrationskilder beskriver lokal Modbus på SPH 3000/3600 via den RS485-mærkede RJ45-port og det midterste blå par, ben 4 og 5. Enjoyelec angiver ben 4 som RS485 B og ben 5 som RS485 A. En dokumenteret SPH3600-installation i Homey-fællesskabet bekræfter de samme fysiske ben og kræver, at inverterportens funktion sættes til `VPP`.

Kilderne bruger modsatte A/B-navne. Det er almindeligt, fordi RS485-udstyr ikke altid bruger A/B konsekvent. Brug derfor ben 4 og 5 som det sikre fysiske udgangspunkt, og byt kun de to ledere ved USB-adapterens skrueterminal, hvis en read-only forespørgsel ikke giver svar.

Dette er stadig commissioning og ikke en verificeret produktionsprofil. `CAN`- og `METER`-kablerne skal blive siddende.

## Konkret kabelmapping

Med et almindeligt T568B-kabel:

| RJ45-ben | T568B-farve | Primær tilslutning på USB-RS485 |
|---:|---|---|
| 4 | Blå | `B`, `B-`, `D-` eller `RS485-` |
| 5 | Hvid/blå | `A`, `A+`, `D+` eller `RS485+` |

Alle andre RJ45-ben skal være uforbundne og isoleres enkeltvis. Der må ikke hentes 5 V, 12 V eller anden forsyning fra inverterstikket. Brug ikke USB-TTL.

Hold RJ45-stikket med guldkontakterne mod dig og låsetappen væk fra dig. Ben 1 er yderst til venstre og ben 8 yderst til højre. Kontrollér orienteringen med et multimeter efter crimpning; stol ikke kun på lederfarven.

## Vurdering af GavChap-projektet

Repositoryet `GavChap/growatt-sph-rs485-to-mqtt` er nyttigt som proof-of-concept for read-only Modbus, men ikke som kabeldokumentation:

- Forfatteren oplyser, at det er testet med en nyere Growatt SPH-3600 og en batteripakke.
- README-filen indeholder ingen pinout og henviser udtrykkeligt til inverterens manual eller et færdigt korrekt kabel.
- Koden bruger Modbus RTU med 9600 baud, 8 databits, ingen parity, 1 stopbit og 1 sekunds timeout.
- Den udfører kun function 04 (`read_input_registers`) i de viste pollingkald.
- Den bruger unit/slave-ID 0. Det er usædvanligt for almindelig Modbus-adressering og skal verificeres mod den konkrete inverter; det må ikke ukritisk kopieres som produktionsstandard.
- Den læser grupperne 0–99 og 1000–1099 og udpeger blandt andet PV-effekt, netspænding, frekvens, SOC, lade-/afladeeffekt og lokal belastning. Registerkortet er ikke kildebelagt i repositoryet og behandles derfor kun som en commissioning-kandidat.
- Projektet har ingen implementerede writes. Det giver ingen dokumentation for sikre styreregistre eller safe state.

Konklusionen er, at projektet styrker hypotesen om Growatt Modbus v2 og giver gode read-only testkandidater, men det identificerer hverken den korrekte port eller benforbindelsen på det fotograferede anlæg.

## Det kan forberedes nu

Anskaff eller klargør:

- Et kort stykke Cat5e/Cat6 med snoede par.
- Et skærmet, galvanisk isoleret USB-RS485-interface med klemmer mærket `A/+` og `B/-`. Interface og computer må ikke forsynes fra inverterens modularstik.
- RJ45-stik passende til kabeltypen og crimptangen.
- Multimeter med DC-spændingsmåling.
- Mærkater til begge kabelender.

Til den første read-only test forbindes kun det blå differentialpar. Eventuel signal-ground tilsluttes ikke.

## Oplysninger der fortsat skal dokumenteres

1. Et skarpt foto af hele inverterens typeskilt med fuldt modelnavn, hardwarevariant og serienummer.
2. Firmwareversionerne fra LCD-menuen.
3. Foto af ShineWiFi-S og den port, hvor loggeren faktisk er tilsluttet.
4. Batteriets typeskilt/model. `CAN`-kablet skal blive siddende.
5. Den præcise menuvisning, hvor RS485-porten sættes til `VPP`, uden at ændre andre indstillinger.

## Fremstilling og første test

1. Sluk ikke, åbn ikke og omforbind ikke net-, PV- eller batteridelen. Arbejde på effektinstallation udføres af en kvalificeret person.
2. Identificér den ledige inverterport med den trykte mærkning `RS485`. Kablet må aldrig prøves i `CAN`, `DRMS`, `METER`, `CT`, `NTC`, dry-contact eller en BMS-port med en anden funktion. Modularportene er ikke indbyrdes kompatible, selv om stikket passer fysisk.
3. Hold RJ45-stikket med kontaktfladerne synlige og låsetappen væk fra dig. Nummerér benene 1–8 fra venstre mod højre, og kontrollér orienteringen mod diagrammet to gange.
4. Crimp et T568B RJ45-stik. Ved adapterenden bruges kun blå (ben 4) og hvid/blå (ben 5). Skriv mappingen på kabelmærkaten.
5. Kontinuitetsmål hvert anvendt ben fra RJ45-kontakten til adapterenden. Bekræft samtidig, at ingen anvendte eller ubrugte ledere er kortsluttet.
6. Før USB-adapteren tilsluttes: mål DC-spænding på det dokumenterede differentialpar og fra hvert signal til signal-ground/chassis. Stop ved en uventet spænding; gæt ikke.
7. Forbind hvid/blå til adapterens `A/+` og blå til `B/-`. Hvis der ingen respons er, afbryd USB og byt kun de to ledere ved adapteren — aldrig til andre RJ45-ben.
8. Lad terminering være deaktiveret ved det første korte punkt-til-punkt-kabel, medmindre den officielle dokumentation kræver andet.
9. Sæt RS485-portens funktion til `VPP` i invertermenuen. Ændr ingen lande-, net-, batteri- eller beskyttelsesindstillinger.
10. Start med 9600 baud, 8 databits, ingen parity, 1 stopbit og slave-ID 1. Brug udelukkende Modbus function 04 mod en lille, kendt read-only gruppe. Ingen scanning af hele registerrummet og ingen function 06/16.
11. Hvis der ingen respons er: kontrollér først portfunktion og kabelkontinuitet, afbryd USB, byt derefter A/B ved adapteren og prøv samme request igen. Test ikke tilfældige RJ45-ben.
12. Log slave-ID, request/response-hex og CRC. Sammenlign læste værdier med LCD'et, før signaler navngives.

## Sådan sættes RS485-porten til VPP

Menutekster og knapper kan variere lidt med firmware, men Growatts SPH-menu bruger denne sti:

1. Lad inverteren være i normal drift. Kablet behøver ikke være tilsluttet endnu.
2. Hold `Enter` inde i cirka 3 sekunder for at åbne indstillingsmenuen.
3. Brug pil op/ned til `WorkMode`, og tryk `Enter`.
4. Gå til `RS485 Setting` (kan stå som `RS485 Seting`), og tryk `Enter`.
5. Vælg feltet `Port` og derefter `VPP` blandt `Shinemaster`, `Meter2`, `VPP` og `unused`.
6. Hold `Enter` inde i cirka 3 sekunder for at bekræfte/gemme. Et kort tryk er på nogle firmwareversioner kun navigation.
7. Gå ud med `ESC`. Åbn derefter samme menu igen, og kontrollér at `Port: VPP` stadig vises.

Vælg ikke `Default set`, `Country/Area`, `Set Region`, `ExportLimit`, batteritype eller andre nabopunkter. `Default set` kan nulstille foruddefinerede parametre. Hvis `RS485 Setting` eller `VPP` ikke findes, stop og fotografer menuskærmen; brug ikke en anden kommunikationstilstand som gæt.

## Stopbetingelser

Stop straks ved varm adapter, lugt, udfald i batterikommunikation, inverteralarm, ustabil CAN/METER-status eller uventet spænding. Fjern USB-kablet fra computeren før ledninger flyttes. Der må aldrig bruges en passiv USB-TTL-adapter; interfacet skal være et rigtigt RS485-interface.
