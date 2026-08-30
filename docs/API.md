# API

- `GET /api/v1/state`: normaliseret simulator/live-state med kilde, kvalitet og timestamps.
- `GET /api/v1/health`: maskinlæsbar sundhedstilstand.
- `GET /healthz`: let healthcheck.

Skriveendpoints skal først eksponeres, når databaseauth, rollecheck, CSRF, rate-limit, audit og command queue er aktive; de er derfor ikke åbne i denne sikre MVP-baseline.
