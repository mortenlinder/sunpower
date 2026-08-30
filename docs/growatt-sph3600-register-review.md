# Growatt SPH3600 - registergennemgang

Status: Kandidater til commissioning. Modbus-writes er fortsat låst.

## Kilder

- Growatt Inverter Modbus RTU Protocol II v1.20, dateret 2020-04-13.
- Community-test: <https://github.com/bobbesnl/ModbusGrowatt_HomeAssistant>

## Dokumenterede holding-registre

| Adresse | Funktion | Værdi |
|---:|---|---|
| 1070 | Grid First discharge power rate | 0-100 %, ikke watt |
| 1071 | Grid First stop-SOC | 0-100 % |
| 1080/1081/1082 | Grid First periode 1 | start, stop, enable |
| 1083/1084/1085 | Grid First periode 2 | start, stop, enable |
| 1086/1087/1088 | Grid First periode 3 | start, stop, enable |
| 1090 | Battery First charge power rate | 0-100 %, ikke et klokkeslæt |
| 1091 | Battery First stop-SOC | 0-100 %, ikke et klokkeslæt |
| 1092 | AC charge enable under Battery First | 0/1 |
| 1100/1101/1102 | Battery First periode 1 | start, stop, enable |
| 1103/1104/1105 | Battery First periode 2 | start, stop, enable |
| 1106/1107/1108 | Battery First periode 3 | start, stop, enable |

Klokkeslæt pakkes i ét 16-bit register: høj byte er time, lav byte er minut.

## Vigtig kildekonflikt

Community-README'en kalder 1090 og 1091 for AC-charge start og stop og viser `3000` som værdi til 1070. Det er i konflikt med protokoltabellen:

- 1090 er ladeeffekt i procent.
- 1091 er stop-SOC i procent.
- 1070 accepterer 0-100 procent, ikke watt.
- AC-ladning aktiveres med 1092 og følger Battery First-perioderne ved 1100-1108.

Disse README-eksempler må ikke kopieres til Solportalen.

## Load First og fallback

Protokollen viser særskilte Load First-registre 1110-1118, men markerer dem `SPA/reserve`. De må derfor ikke bruges på SPH uden konkret readback-test. Community-kilden oplyser, at SPH falder tilbage til Load First, når Battery First og Grid First er deaktiveret. Det er en sandsynlig fallback, men endnu ikke en verificeret safe state på dette anlæg.

Før writes frigives skal commissioning derfor:

1. læse holding-register 1070-1108 med function 03 og gemme baseline;
2. sammenligne værdierne med de aktive indstillinger i ShinePhone;
3. verificere præcis model og firmware;
4. udføre én afgrænset write med øjeblikkelig readback;
5. verificere fallback til Load First både i register-readback og fysisk drift.
