# Sikkerhedsmodel

Writes er deaktiveret som standard og kan aldrig udføres fra webprocessen. Ikke-verificerede profiler afvises ved aktive writes. Landekode, netstandard, anti-islanding, spænding/frekvensbeskyttelse, relæer, batterikemi/BMS, kalibrering, fabriksregistre, installatørkoder og firmware er absolutte denylist-kategorier. Enhver tilladt write kræver validering, idempotens, databasekø, worker-udførelse, readback og auditpost. Gentagne fejl skal åbne circuit breaker.
