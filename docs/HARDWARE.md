# Hardwarestatus

- Inverter: Growatt SPH3600
- Serienummer: OMCUCCN05E (installationsnote, ikke modeldetektion)
- Aktuel logger: ShineWiFi-S
- Mål: lokal RS485 via USB-dongle
- Watts-enhed: findes; præcis model og firmware er ukendt
- Prisområde: DK2

## Fotodokumentation 24. august 2026

De modtagne billeder viser en Growatt-hybridinverter med separat kommunikationspanel. Følgende kan konstateres direkte på panelet:

- En ledig modularport er tydeligt mærket `RS485`. Integrationsdokumentation for SPH3000/3600 angiver lokal inverter-Modbus via ben 4 og 5, når portfunktionen er sat til `VPP`. Dette skal commissioning-verificeres på den konkrete inverter.
- Separate porte er mærket `METER`, `CT`, `DRMS`, `NTC` og `CAN`.
- `METER`-porten er i brug med et hvidt kabel mærket "METER".
- `CAN`-porten er i brug med et sort kabel, sandsynligvis til batterikommunikation. Funktionen skal bekræftes, før kablet berøres.
- Batteriets kraftterminaler er mærket `BAT+` og `BAT-`.
- Der findes en DIP-switchblok med positionerne 1, 2 og 3. Dens funktion og aktuelle stilling må ikke udledes alene af billedet.

Billedet og de nye integrationskilder giver nu et forsvarligt read-only commissioning-forsøg på den RS485-mærkede port: RJ45-ben 4/5, portfunktion `VPP`, 9600 8N1 og slave-ID 1. A/B-navngivningen er modstridende mellem kilderne, så polariteten verificeres ved kontrolleret ombytning på adapterenden.

Før rigtig profil kan aktiveres mangler et skarpt foto af inverterens typeskilt, firmwarevisning, batteriets typeskilt/model og kapacitet, RS485-stiktype og pinout, baudrate, parity, slave-ID, verificeret registerkort, write-kommandoer og safe state. Kommunikationsportenes placering og mærkning er nu dokumenteret.

## Verificeret Modbus-forbindelse

En read-only commissioning-test har etableret fungerende Modbus RTU gennem den RS485-mærkede RJ45-port:

- Raspberry Pi med CH341 USB-RS485-adapter som `/dev/ttyUSB0`.
- Inverterens port sat til `VPP`.
- RJ45-ben 4 og 5 anvendt.
- 9600 baud, 8 databits, ingen parity, 1 stopbit.
- Slave-ID 1.
- Function 04, startadresse 0, 10 inputregistre.
- Request: `01 04 00 00 00 0a 70 0d`.
- Response: `01 04 14 00 05 00 00 37 14 0a 5e 00 19 00 00 1a 45 0c 67 00 17 00 00 ad 5b`.
- CRC valideret korrekt.
- Rå registre: `[5, 0, 14100, 2654, 25, 0, 6725, 3175, 23, 0]`.

Dette verificerer transport og read-only kommunikation, men endnu ikke betydningen eller skaleringen af hvert register. Ingen writes er udført.
