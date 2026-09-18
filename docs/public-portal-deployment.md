# Public portal: Apache / PHP / MariaDB, no Composer

## Boundaries

`portal/public` is the **only** document root. Never expose the Raspberry Pi's
`public/` router, root `.env`, local unauthenticated API, cloud configuration,
test utilities, or source root to the internet. Use a separate database and
Unix/PHP-FPM user. No changes to other ISPConfig sites are needed.

The Pi connects outbound to `https://solpanel.linder.dk/?api=exchange`. The new
agent never opens RS485. It queues a bounded command, and the existing device
worker verifies register writes. Remote permission defaults off; existing local
automation and write configuration are preserved.

## Deploy checklist

1. Create the ISPConfig website for `solpanel.linder.dk`, HTTPS certificate and
   PHP 8.2+ FPM with PDO MySQL, cURL and mbstring. DNS must resolve to web01.
2. Place the portal outside public web root. Point document root at
   `portal/public` (or copy its contents to the assigned web directory and keep
   the rest in its parent with the same paths). Disable directory listings.
3. Copy `portal/config.example.php` to `portal/config.php`, mode 0640; fill in
   separate DB credentials, a 32-byte random hex secret and the fixed HTTPS URL.
4. `php portal/bin/maintenance.php migrate` with the site user.
5. Run `php portal/bin/maintenance.php` every minute as the same site user.
   Monitor its errors and mail queue; the worker retries delivery eight times.
6. Configure the sender identity, DKIM/SPF/DMARC and the local sendmail transport.
   Test **real mailbox receipt**, including verification and reset, before setting
   `registration_enabled` true. Sendmail exit 0 means accepted into the MTA,
   not delivered to the recipient. Do not claim delivery from a unit test.
7. Build the PDF and run `scripts/build-public-release.py`. Publish only the
   generated release, checksum and PDF to `/downloads`. Keep previous releases
   in a private rollback directory. Verify checksum after deployment.
8. Test HTTPS redirects, headers, register/verify/login/reset/logout, wrong CSRF,
   expired/replayed links, two-user installation isolation, fresh/stale telemetry,
   mobile layouts and downloads. Existing `portal/tests/integration.php` uses an
   isolated random test schema and does not touch the production database.
9. Install Pi cloud code/service after regression tests. Do not overwrite `.env`,
   `var` or database. Pair it from a **verified owner's account**, then enable
   remote control locally only after explicit owner approval/commissioning.

## Commands on the Pi

```
sudo -u solportal php /opt/solportalen/bin/solportal cloud:pair
sudo systemctl enable --now solportal-cloud
sudo -u solportal php /opt/solportalen/bin/solportal cloud:control enable
sudo -u solportal php /opt/solportalen/bin/solportal cloud:control disable
sudo -u solportal php /opt/solportalen/bin/solportal cloud:unpair
```

Parring prompts for its one-time code; do not put secrets in arguments or git.
`var/cloud-device.json` is mode 0600, owned by solportal. Back it up privately.
Neither the portal nor the agent can set arbitrary register addresses or shell
commands. Owner commands require password re-entry, a verified session, CSRF,
fresh telemetry and local write/control permissions. Commands expire undelivered
after two minutes, are idempotent locally, and override the plan for 15–120 minutes
(capped at local midnight). On expiry/revocation/crash recovery the device worker
verifies fallback, clears stale queued plans, then releases local planning.
If the worker or Pi itself is down, software cannot guarantee a fallback write;
the programmed charging windows are bounded but recur daily until cleared.
Hardware-independent watchdog requirements should be reviewed before broader use.

## Privacy / retention

Public sharing is opt-in. Only sampled solar production contributes to completed,
delayed half-hour groups of at least five installations. No household consumption,
battery, owner or location is public. Do not call this guaranteed mathematical
anonymization. Data gaps are not replaced with fabricated zeroes. Energy totals
are estimated from one-minute power samples, not billing meter values.
Raw cloud minute history is retained 24 months, as are command/audit records.
The existing local retention policy is independent. No historical backfill is
implemented yet; an internet outage leaves a visible cloud gap.

The administrator must publish their real contact/data-controller details before
opening registration beyond a private pilot and provide export/deletion support.
No marketing cookies or third-party fonts/analytics are included.

## Security references

Token/reset and session behavior follows the relevant OWASP checklists:
- https://cheatsheetseries.owasp.org/cheatsheets/Forgot_Password_Cheat_Sheet.html
- https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html

## Test preview (never production)

On an isolated Linux test environment, `php portal/tests/preview-setup.php`
creates `solportal_cloud_preview` and refuses to overwrite existing portal config.
Bind `php -S 127.0.0.1:8765 -t portal/public portal/tests/preview-router.php`
to loopback and use an SSH tunnel. Preview data is artificial and labelled.
The router's HTTPS override is exclusively for this loopback test. Exclude all
`portal/tests/` files from deployment.
