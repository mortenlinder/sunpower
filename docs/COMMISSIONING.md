# Commissioning

1. Installer og afprøv simulatoren.
2. Konfigurer Watts, hvis den understøtter lokal MQTT.
3. Brug den ledige port mærket `RS485` som commissioning-kandidat med RJ45-ben 4 og 5. Lad `METER`- og `CAN`-kablerne blive siddende.
4. Sæt portfunktionen til `VPP`, og brug kabelmappingen i `RS485_CABLE_GUIDE.md`. Kontrollér benorientering og kontinuitet med multimeter inden tilslutning.
5. Tilslut først derefter USB-RS485 uden writes, og vælg `/dev/serial/by-id/...`.
6. Bekræft baudrate, parity og slave-ID med kontrollerede function 03/04-reads (maks. 60 registre; ingen scanning).
7. Sammenlign hvert signal med inverterdisplay/ShinePhone; verificer datatype, fortegn og skalering.
8. Opret en kildebelagt profil, valider den, og kør monitor- og shadow mode gennem en repræsentativ periode.
9. Tag et verificeret baseline-snapshot. Verificer enkeltvise højniveauwrites og readback under opsyn.
10. Definér og verificer safe state. Aktivér først derefter automatikken.
11. Fjern ShineWiFi-S efter vellykket parallel verifikation.

Der må aldrig improviseres registre eller safe state.

### Aktuel status

Trin 3–6 er gennemført for read-only transport: RS485-port i VPP-mode, RJ45-ben 4/5, CH341-adapter, 9600 8N1, slave-ID 1 og function 04 er verificeret med gyldig respons og CRC. Næste trin er at sammenholde små registergrupper med samtidige LCD-værdier.
