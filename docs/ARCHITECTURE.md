# Arkitektur

Apache afleverer kun `public/` til den server-renderede PHP-webapp. Webbrugeren har ingen adgang til serieporte. PHP CLI-worker, scheduler og valgfri MQTT-listener kører som den begrænsede bruger `solportal`. MySQL/MariaDB er systemets delte command queue, historik, auditspor og heartbeat-lager.

En lokal autoloader i `bootstrap.php` mapper `Solportalen\\` til `src/`; Composer indgår ikke. Hardware oversættes gennem JSON-profiler til normaliseret telemetri. Simulatorprofilen er komplet, mens Growatt-profilen bevidst er tom og write-låst indtil fysisk verificering.
