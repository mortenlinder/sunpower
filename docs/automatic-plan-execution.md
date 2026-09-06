# Rullende planer og faktisk invertertilstand

Automatikken genvurderer planen ved scheduler-kørsler, når prishorisonten
udvides, eller når der er gået mindst 15 minutter siden sidste køsætning.
Dermed bruges aktuelt batteriniveau og forbrug også uden nye markedspriser.
En igangværende kommando bliver ikke erstattet af endnu en automatisk kommando.

Growatt-vinduer indeholder klokkeslæt, ikke datoer. Derfor indlæses kun
resten af den lokale kalenderdag. Den automatiske scheduler indlæser nye
vinduer næste dag inden for den valgte aktiveringsperiode. Uden automatik
skal en ny plan anvendes manuelt efter udløb; den valgte fallback overtager.
Inverteren har højst tre vinduer af hver type. Det aktuelle vindue prioriteres
før udvælgelsen af øvrige vinduer, så opladning nu ikke forsvinder til fordel
for tre senere vinduer.

Planforslag er ikke bevis på anvendelse. API'et skelner mellem seneste forslag,
aktivt plan-id og målt udførelse. Under et ladevindue vises:

- Opladning bekræftet: Battery First, AC-opladning aktiveret og målt
  ladeeffekt over 50 W. Dette betyder ikke, at al ladeenergi kommer fra nettet.
- Lademål nået: målt SOC har nået inverterens aktuelle SOC-grænse.
- Ikke bekræftet: planens ønskede opladning ses ikke i de faktiske målinger.
- Gamle data: inverterens egne målinger er over 30 sekunder gamle;
  friske Watts-målinger kan ikke bekræfte inverterens tilstand.

Writes verificeres fortsat med register-readback. Automatisk genplanlægning
er ikke en garanti mod hardware-, forbindelses- eller batteribegrænsninger.
Ved fejl skal kommandoens fejl og den faktiske invertertilstand kontrolleres.

`php bin/solportal automation:run` genererer et forslag og lader den normale
aktiverings-, tids- og write-kontrol afgøre, om det må sættes i kø.
