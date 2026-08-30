<?php
declare(strict_types=1);
/** @var array $state */
$kw = static fn (float|int $w): string => number_format(abs((float) $w) / 1000, 2, ',', '.') . ' kW';
$batteryText = $state['battery_power_w'] > 50 ? 'Batteriet hjælper huset' : ($state['battery_power_w'] < -50 ? 'Overskuddet lagres' : 'Batteriet er i hvile');
$gridText = $state['grid_power_w'] > 50 ? 'Der hentes energi fra nettet' : ($state['grid_power_w'] < -50 ? 'Overskud sendes til nettet' : 'Næsten intet netflow');
$writesEnabled = Solportalen\Config\Env::bool('WRITES_ENABLED', false);
?>
<!doctype html>
<html lang="da" data-theme="dark">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="theme-color" content="#06110e"><title>Solportalen</title>
  <link rel="manifest" href="/manifest.webmanifest"><link rel="stylesheet" href="/assets/css/app.css"><link rel="stylesheet" href="/assets/css/insights.css"><link rel="stylesheet" href="/assets/css/learning.css"><link rel="stylesheet" href="/assets/css/plan.css"><link rel="stylesheet" href="/assets/css/control.css?v=6">
  <script src="/assets/js/app.js?v=6" defer></script>
</head>
<body class="<?= $wallboard ? 'wallboard' : '' ?>" data-mode="<?= htmlspecialchars($mode, ENT_QUOTES) ?>">
<header>
  <div class="brand"><span class="sunmark"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M4.9 4.9l1.4 1.4m11.4 11.4 1.4 1.4M2 12h2m16 0h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg></span><div><strong>Solportalen</strong><small>Lokal energi · live fra dit anlæg</small></div></div>
  <div class="status"><span class="pill source-pill"><?= $mode === 'simulator' ? 'SIMULATOR' : 'GROWATT · RS485' ?></span><span class="pill read-pill"><?= $writesEnabled ? 'WRITES ARMED' : 'READ ONLY' ?></span><span class="live-dot"></span><span id="clock"></span></div>
</header>
<div class="layout">
<?php if (!$wallboard): ?><nav><div class="nav-label">SOLPORTALEN</div><a class="active" href="/"><span>◉</span>Overblik</a><a href="#flow"><span>⌁</span>Energiflow</a><a href="/weather"><span>☀</span>Vejrprognose</a><a href="/prices"><span>↗</span>Elprisprognose</a><a href="/suppliers"><span>⌕</span>Leverandørvagt</a><a href="#plan"><span>✦</span>Godkendt plan</a><a href="#historik"><span>⌁</span>Historik</a><div class="nav-label">ANLÆG</div><a href="#batteri"><span>▣</span>Batteri</a><a href="#enheder"><span>◇</span>Enheder</a><a href="/commissioning"><span>⌘</span>Commissioning</a><a href="/wallboard"><span>□</span>Wallboard</a><div class="nav-safety"><i></i><div><b>Sikker tilstand</b><small>Load First uden gyldig plan</small></div></div></nav><?php endif; ?>
<main>
  <section class="hero"><div><p class="eyebrow">DIT ENERGIOVERBLIK</p><h1><span>God eftermiddag.</span> Dit anlæg arbejder for dig.</h1><p id="summary"><?= $online ? 'Live-data fra inverteren – helt lokalt i dit hjem.' : 'Venter på friske data fra inverteren.' ?></p></div><div class="safe"><span><?= $online ? '✓' : '!' ?></span><div><b id="connection-status"><?= $online ? 'Live forbindelse' : 'Data mangler' ?></b><small>Opdaterer hvert 5. sekund</small></div></div></section>

  <section class="metrics">
    <article class="metric solar-card"><div class="metric-icon">☀</div><div><span>Producerer nu</span><b id="pv"><?= $kw($state['pv_power_w']) ?></b><small><i></i>Solpaneler aktive</small></div></article>
    <article class="metric home-card"><div class="metric-icon">⌂</div><div><span>Huset bruger</span><b id="load"><?= $kw($state['load_power_w']) ?></b><small>Direkte forbrug lige nu</small></div></article>
    <article class="metric battery-card"><div class="metric-icon">▣</div><div><span>Batteriniveau</span><b id="soc-big"><?= number_format((float)$state['battery_soc_pct'], 0) ?> %</b><small><i class="soc-bar" style="--soc:<?= (float)$state['battery_soc_pct'] ?>%"></i><span id="battery-flow-label"><?= $kw($state['battery_power_w']) ?></span></small></div></article>
    <article class="metric grid-card"><div class="metric-icon">⇅</div><div><span>Elnet</span><b id="grid"><?= $kw($state['grid_power_w']) ?></b><small id="grid-direction"><?= $state['grid_power_w'] >= 0 ? 'Importerer' : 'Eksporterer' ?></small></div></article>
  </section>

  <section class="energy-card" id="flow">
    <div class="section-title"><div><p class="eyebrow">LIVE ENERGIFLOW</p><h2>Se energien bevæge sig</h2></div><div class="updated"><span></span>Opdateret <b id="updated-time">nu</b></div></div>
    <div class="energy-stage">
      <div class="stage-glow"></div>
      <svg class="energy-lines" viewBox="0 0 1000 570" preserveAspectRatio="none" aria-hidden="true">
        <defs><filter id="glow"><feGaussianBlur stdDeviation="4" result="blur"/><feMerge><feMergeNode in="blur"/><feMergeNode in="SourceGraphic"/></feMerge></filter></defs>
        <path class="track" d="M185 148 C310 148 330 260 450 280"/><path id="flow-solar" class="beam sunbeam" d="M185 148 C310 148 330 260 450 280"/>
        <path class="track" d="M550 280 C680 260 690 148 815 148"/><path id="flow-home" class="beam greenbeam" d="M550 280 C680 260 690 148 815 148"/>
        <path class="track" d="M455 330 C350 365 320 445 205 450"/><path id="flow-battery" class="beam limebeam" d="M455 330 C350 365 320 445 205 450"/>
        <path class="track" d="M545 330 C650 365 680 445 795 450"/><path id="flow-grid" class="beam bluebeam" d="M545 330 C650 365 680 445 795 450"/>
      </svg>
      <div class="energy-node node-solar"><div class="node-icon">☀</div><span>Solpaneler</span><b id="flow-pv"><?= $kw($state['pv_power_w']) ?></b><small>produktion</small></div>
      <div class="energy-node node-home"><div class="node-icon">⌂</div><span>Dit hjem</span><b id="flow-load"><?= $kw($state['load_power_w']) ?></b><small>forbrug</small></div>
      <div class="energy-node node-battery"><div class="node-icon battery-gauge"><i id="battery-fill" style="--soc:<?= (float)$state['battery_soc_pct'] ?>%"></i>▣</div><span>Batteri</span><b id="flow-soc"><?= number_format((float)$state['battery_soc_pct'], 0) ?> %</b><small id="flow-battery-value"><?= $kw($state['battery_power_w']) ?></small></div>
      <div class="energy-node node-grid"><div class="node-icon">⇅</div><span>Offentligt net</span><b id="flow-grid-value"><?= $kw($state['grid_power_w']) ?></b><small id="flow-grid-label"><?= $state['grid_power_w'] >= 0 ? 'import' : 'eksport' ?></small></div>
      <div class="inverter studio"><div class="inverter-halo"></div><img src="/assets/images/growatt-inverter-studio.png" alt="Growatt hybridinverter"><div class="inverter-chip"><i></i><span><b>Growatt SPH</b><small id="inverter-state"><?= htmlspecialchars(match($state['priority_mode']??'unknown'){'load_first'=>'Load First','battery_first'=>'Battery First','grid_first'=>'Grid First',default=>'Mode afventer'},ENT_QUOTES) ?> · VPP</small></span></div></div>
    </div>
    <div class="flow-caption"><span class="pulse-ring"></span><div><b id="battery-state"><?= $batteryText ?></b><small id="grid-state"><?= $gridText ?></small></div><em>Live analyse</em></div>
  </section>

  <section class="intelligence" id="intelligent"><span id="weather"></span><span id="prices"></span>
    <article class="forecast-card"><div class="section-title"><div><p class="eyebrow">VEJRET I VÆRLØSE</p><h2>Solbarometer</h2></div><span class="source-badge">YR · 48 TIMER</span></div><div class="solar-overview"><div class="solar-dial" id="solar-dial" style="--score:0"><strong id="solar-score">—</strong><small>ud af 100</small></div><div><h3 id="solar-label">Henter prognose…</h3><p id="solar-copy">Solportalen vurderer skydække, dagslys og forventet produktion.</p></div></div><div class="weather-strip" id="weather-strip"><span class="skeleton"></span><span class="skeleton"></span><span class="skeleton"></span><span class="skeleton"></span></div></article>
    <article class="price-card"><div class="section-title"><div><p class="eyebrow">DK2 · SAMLET PRIS</p><h2>I dag og i morgen</h2></div><span class="source-badge">15 MIN.</span></div><div class="price-summary"><div><small>Lige nu</small><b id="price-now">—</b></div><div><small>Billigste interval</small><b id="price-low">—</b></div><div><small>Dyreste interval</small><b id="price-high">—</b></div></div><canvas id="price-chart" height="160" aria-label="Samlet elpris med referenceværdier"></canvas><div class="price-legend"><span>Spot + nettarif + afgifter + moms</span><b>Kr./kWh</b></div></article>
    <article class="plan-card"><div class="plan-head"><span class="brain">✦</span><div><p class="eyebrow">INTELLIGENT PLAN</p><h2>Shadow mode</h2></div><span class="readonly-badge"><?= $writesEnabled ? 'WRITE-KANAL KLAR' : 'INGEN WRITES' ?></span></div><p class="plan-lead" id="plan-action">Afventer pris- og vejrdata.</p><div class="timeline" id="plan-timeline"></div><div class="consumption-watch"><span>⌁</span><div><b>Forbrugsvagten lærer</b><small id="consumption-status">Ingen elbilhændelser genkendt endnu</small></div></div><div class="plan-note"><span>✓</span><p><b><?= $writesEnabled ? 'Skrivekanal aktiveret' : 'Sikker rådgivning' ?></b><small><?= $writesEnabled ? 'Planexecutor er endnu ikke sat i automatisk drift.' : 'Planen beregnes, men inverteren styres ikke.' ?></small></p></div></article>
  </section>

  <section class="full-plan" id="plan">
    <div class="plan-toolbar"><div><p class="eyebrow">24–48 TIMERS BATTERIPLAN</p><h2>Planlagt efter pris, sol og dit forbrug</h2><p id="plan-explanation">Planen dannes, når der er tilstrækkelige prognosedata.</p></div><div class="plan-kpis"><div><small>Forventet besparelse</small><b id="plan-saving">—</b></div><div><small>Horisont</small><b id="plan-horizon">—</b></div><div><small>Aktive intervaller</small><b id="plan-active-count">—</b></div></div></div>
    <div class="plan-chart-wrap"><canvas id="plan-chart" height="230" aria-label="Batteriplan med pris og SOC"></canvas></div>
    <div class="plan-actions-legend"><span class="charge">Oplad</span><span class="solar-charge">Gem sol</span><span class="hold">Hold</span><span class="discharge">Aflad</span><span class="soc">SOC</span></div>
    <div class="plan-table-wrap"><table><thead><tr><th>Tid</th><th>Handling</th><th>Effekt</th><th>SOC</th><th>Pris</th><th>Begrundelse</th></tr></thead><tbody id="plan-rows"><tr><td colspan="6">Afventer første komplette plan…</td></tr></tbody></table></div>
    <div class="approval-panel"><div><span class="approval-icon">✓</span><div><b id="approval-title">Gennemse og gem planen</b><small id="approval-copy">Planen får en fast udløbstid. Derefter vælges Load First automatisk.</small></div></div><div class="approval-buttons"><button id="approve-plan" type="button" disabled>Gem og godkend planen</button><button id="apply-plan" type="button" disabled>Anvend på inverter</button></div></div>
  </section>

  <section class="lower-grid" id="historik">
    <article class="chart-card"><div class="section-title"><div><p class="eyebrow">SENESTE MINUTTER</p><h2>Anlæggets puls</h2></div><b id="sample-count">0 målepunkter</b></div><div class="legend"><span class="pv">Sol</span><span class="load">Hus</span><span class="battery">Batteri</span><span class="gridline">Net</span></div><canvas id="chart" height="190" aria-label="Live effektgraf"></canvas></article>
    <aside><article class="insight"><div class="insight-top"><span>LIVE INDSIGT</span><i>✦</i></div><h3 id="insight-title"><?= $batteryText ?></h3><p>Solportalen følger energiens vej lokalt og holder styring bag godkendte planer.</p><div class="readonly"><span>✓</span><div><b><?= $writesEnabled ? 'Write-kanal klar' : 'Read-only beskyttelse' ?></b><small><?= $writesEnabled ? 'Automatisk executor er endnu ikke aktiv' : 'Modbus-writes er deaktiveret' ?></small></div></div></article></aside>
  </section>
</main></div>
<footer><span id="footer-source"><?= $mode === 'simulator' ? 'Data er simulerede' : 'Live read-only RS485-data' ?></span> · Data forlader ikke dit lokale netværk · <a href="/healthz">Systemstatus</a></footer>
</body></html>
