# Installation (Apache)

Kræver Linux, Apache 2.4, PHP 8.2+ (`cli`, `pdo_mysql`, `curl`, `json`, `mbstring`) og MariaDB/MySQL. Der bruges ikke Composer.

1. Kopiér projektet til `/opt/solportalen`, og opret systembrugeren `solportal`.
2. Kopiér `.env.example` til `.env`, indsæt en nøgle fra `php bin/solportal app:key`, og sæt databasecredentials.
3. Kør `php bin/solportal database:migrate` og `php bin/solportal test`.
4. Installér `apache/solportalen.conf`; ret domæne og TLS-certifikater. Apache-brugeren må ikke være medlem af `dialout`.
5. Installér systemd-enhederne. Kun `solportal`-brugeren får adgang til den specifikke serieport via udev/dialout.

Tag databasebackup før opgradering. Rollback sker ved at gendanne forrige applikationsmappe og dens matchende databasebackup.
