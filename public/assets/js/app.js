'use strict';

const fmt = value => `${(Math.abs(Number(value) || 0) / 1000).toLocaleString('da-DK', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
})} kW`;
const samples = [];
let currentPlan = null;

function clock() {
    const element = document.querySelector('#clock');
    if (element) element.textContent = new Date().toLocaleString('da-DK', {hour: '2-digit', minute: '2-digit'});
}

function draw() {
    const canvas = document.querySelector('#chart');
    if (!canvas) return;
    const ratio = devicePixelRatio || 1;
    const width = canvas.clientWidth;
    const height = 190;
    if (canvas.width !== Math.round(width * ratio)) {
        canvas.width = Math.round(width * ratio);
        canvas.height = Math.round(height * ratio);
    }
    const context = canvas.getContext('2d');
    context.setTransform(ratio, 0, 0, ratio, 0, 0);
    context.clearRect(0, 0, width, height);
    const left = 46, right = 10, top = 10, bottom = 24;
    const plotWidth = width - left - right, plotHeight = height - top - bottom;
    context.font = '10px system-ui';
    context.fillStyle = '#789287';
    context.strokeStyle = '#234238';
    context.lineWidth = 1;
    for (let row = 0; row < 4; row++) {
        context.beginPath();
        const y = top + row * plotHeight / 3;
        context.moveTo(left, y);
        context.lineTo(width - right, y);
        context.stroke();
    }
    const maximum = Math.max(1000, ...samples.flatMap(sample => [sample.pv, sample.load, Math.abs(sample.battery), Math.abs(sample.grid)]));
    for (let row = 0; row < 4; row++) {
        const value = maximum * (1 - row / 3) / 1000;
        context.fillText(`${value.toLocaleString('da-DK', {maximumFractionDigits: 1})} kW`, 0, top + row * plotHeight / 3 + 3);
    }
    const spanMinutes = Math.max(1, Math.round((samples.length - 1) * 5 / 60));
    [`−${spanMinutes} min`, `−${Math.round(spanMinutes*2/3)} min`, `−${Math.round(spanMinutes/3)} min`, 'nu'].forEach((label, index) => context.fillText(label, left + index * plotWidth / 3 - (index ? 9 : 0), height - 5));
    for (const [key, color] of [['pv', '#f5c85b'], ['load', '#56e39f'], ['battery', '#b6eb55'], ['grid', '#65a9ff']]) {
        context.strokeStyle = color;
        context.lineWidth = 2;
        context.beginPath();
        samples.forEach((sample, index) => {
            const x = samples.length < 2 ? left : left + index * plotWidth / (samples.length - 1);
            const y = top + plotHeight - (Math.abs(sample[key]) / maximum) * plotHeight;
            index ? context.lineTo(x, y) : context.moveTo(x, y);
        });
        context.stroke();
    }
}

function weatherIcon(symbol = '') {
    if (symbol.includes('rain') || symbol.includes('sleet')) return '☂';
    if (symbol.includes('cloudy')) return '☁';
    if (symbol.includes('partly')) return '◒';
    return '☀';
}

function drawPrice(prices) {
    const canvas = document.querySelector('#price-chart');
    if (!canvas || !prices.length) return;
    const ratio = devicePixelRatio || 1, width = canvas.clientWidth, height = 160;
    canvas.width = Math.round(width * ratio); canvas.height = Math.round(height * ratio);
    const context = canvas.getContext('2d'); context.setTransform(ratio, 0, 0, ratio, 0, 0);
    const left = 34, right = 8, top = 8, bottom = 22, plotWidth = width - left - right, plotHeight = height - top - bottom;
    const values = prices.map(price => Number(price.total_dkk_kwh));
    const min = Math.min(...values), max = Math.max(...values, .5), range = Math.max(.2, max - Math.min(0, min));
    context.font = '9px system-ui'; context.fillStyle = '#789287'; context.strokeStyle = '#234238';
    for (let row = 0; row < 3; row++) { const y = top + row * plotHeight / 2; context.beginPath(); context.moveTo(left,y); context.lineTo(width-right,y); context.stroke(); context.fillText(`${(max-row*range/2).toFixed(2)}`,0,y+3); }
    const gradient = context.createLinearGradient(0,top,0,height-bottom); gradient.addColorStop(0,'rgba(67,224,154,.5)'); gradient.addColorStop(1,'rgba(67,224,154,.02)');
    context.beginPath(); values.forEach((value,index) => { const x=left+index*plotWidth/Math.max(1,values.length-1); const y=top+(max-value)/range*plotHeight; index?context.lineTo(x,y):context.moveTo(x,y); }); context.lineTo(width-right,height-bottom); context.lineTo(left,height-bottom); context.closePath(); context.fillStyle=gradient; context.fill();
    context.beginPath(); values.forEach((value,index) => { const x=left+index*plotWidth/Math.max(1,values.length-1); const y=top+(max-value)/range*plotHeight; index?context.lineTo(x,y):context.moveTo(x,y); }); context.strokeStyle='#43e09a'; context.lineWidth=2; context.stroke();
    context.fillStyle='#789287'; context.fillText('nu',left,height-4); context.fillText('+24 t',left+plotWidth/2-10,height-4); context.fillText('+48 t',width-right-25,height-4);
}

function actionName(action) {
    return {charge_grid:'Oplad fra net',charge_solar:'Gem sol',discharge:'Aflad',hold:'Hold'}[action] || action;
}

function drawPlan(plan) {
    const canvas=document.querySelector('#plan-chart'), rows=plan?.intervals||[]; if(!canvas||!rows.length)return;
    const ratio=devicePixelRatio||1,width=canvas.clientWidth,height=230;canvas.width=Math.round(width*ratio);canvas.height=Math.round(height*ratio);const c=canvas.getContext('2d');c.setTransform(ratio,0,0,ratio,0,0);
    const left=42,right=42,top=12,bottom=25,pw=width-left-right,ph=height-top-bottom,prices=rows.map(r=>Number(r.buy_price)),maxPrice=Math.max(.5,...prices),barWidth=pw/rows.length;
    c.font='9px system-ui';c.fillStyle='#789287';c.strokeStyle='#234238';for(let i=0;i<4;i++){const y=top+i*ph/3;c.beginPath();c.moveTo(left,y);c.lineTo(width-right,y);c.stroke();c.fillText(`${(maxPrice*(1-i/3)).toFixed(1)}`,2,y+3);c.fillText(`${Math.round(100*(1-i/3))}%`,width-right+7,y+3)}
    const colors={charge_grid:'rgba(88,168,255,.32)',charge_solar:'rgba(255,200,77,.32)',discharge:'rgba(67,224,154,.3)',hold:'rgba(82,110,99,.1)'};
    rows.forEach((row,i)=>{const x=left+i*barWidth,y=top+(1-Number(row.buy_price)/maxPrice)*ph;c.fillStyle=colors[row.action]||colors.hold;c.fillRect(x,y,Math.max(1,barWidth),top+ph-y)});
    c.beginPath();rows.forEach((row,i)=>{const x=left+(i+.5)*barWidth,y=top+(1-Number(row.soc_after)/100)*ph;i?c.lineTo(x,y):c.moveTo(x,y)});c.strokeStyle='#b7e34b';c.lineWidth=2;c.shadowColor='#b7e34b';c.shadowBlur=7;c.stroke();c.shadowBlur=0;
    c.fillStyle='#789287';['nu','+12 t','+24 t',plan.horizon_hours>30?'+48 t':'slut'].forEach((label,i)=>c.fillText(label,left+i*pw/3-(i?10:0),height-5));
}

function renderPlan(plan) {
    currentPlan=plan;const rows=plan?.intervals||[];if(!plan?.id||!rows.length)return;
    document.querySelector('#plan-saving').textContent=`${Number(plan.expected_saving_dkk).toLocaleString('da-DK',{minimumFractionDigits:2,maximumFractionDigits:2})} kr.`;
    document.querySelector('#plan-horizon').textContent=`${Number(plan.horizon_hours).toLocaleString('da-DK',{maximumFractionDigits:1})} timer`;
    document.querySelector('#plan-active-count').textContent=plan.action_intervals;
    document.querySelector('#plan-explanation').textContent=plan.explanation;
    const active=rows.filter(row=>row.action!=='hold');document.querySelector('#plan-rows').innerHTML=(active.length?active:rows.slice(0,8)).map(row=>`<tr><td>${new Date(row.starts_at+'Z').toLocaleString('da-DK',{weekday:'short',hour:'2-digit',minute:'2-digit'})}</td><td><span class="action-pill ${row.action}">${actionName(row.action)}</span></td><td>${Number(row.power_w)?fmt(row.power_w):'—'}</td><td>${Number(row.soc_before).toFixed(0)} → ${Number(row.soc_after).toFixed(0)} %</td><td>${Number(row.buy_price).toLocaleString('da-DK',{minimumFractionDigits:2,maximumFractionDigits:2})}</td><td>${row.explanation}</td></tr>`).join('');
    const button=document.querySelector('#approve-plan'),apply=document.querySelector('#apply-plan');button.disabled=false;button.dataset.planId=plan.id;button.dataset.token=plan.csrf_token;apply.disabled=true;
    if(plan.approval_status==='approved_shadow'){button.textContent='Plan gemt og godkendt';button.classList.add('approved');button.disabled=true;apply.disabled=!plan.writes_enabled;document.querySelector('#approval-title').textContent='Godkendt plan';document.querySelector('#approval-copy').textContent=`Gyldig til ${new Date(plan.expires_at+'Z').toLocaleString('da-DK')}. Manuel anvendelse styrer højst 24 timer og falder derefter tilbage til Load First.`}
    if(plan.apply_command?.status==='pending'||plan.apply_command?.status==='claimed'){apply.disabled=true;apply.textContent='Sender til inverter…'}
    if(plan.apply_command?.status==='verified'){apply.disabled=true;apply.textContent='Anvendt på inverter';document.querySelector('#approval-copy').textContent='Growatt-vinduerne er skrevet og verificeret. Automatisk replanning er ikke aktiv.'}
    if(plan.apply_command?.status==='failed'){apply.disabled=false;apply.textContent='Prøv anvendelse igen';document.querySelector('#approval-copy').textContent=plan.apply_command.error_message||'Anvendelsen fejlede og blev rullet tilbage.'}
    if(plan.approval_status==='expired'){button.textContent='Planen er udløbet';button.classList.remove('approved');button.disabled=true;document.querySelector('#approval-title').textContent='Load First er aktiv';document.querySelector('#approval-copy').textContent='Planens gyldighed er slut. Sikker fallback er registreret som Load First.'}
    drawPlan(plan);
}

async function refreshPlan(){try{const response=await fetch('/api/v1/plans/latest',{cache:'no-store'});if(response.ok)renderPlan(await response.json())}catch{/* Forecast timer may not have generated its first plan yet. */}}

async function approvePlan(){const button=document.querySelector('#approve-plan');if(!currentPlan||button.disabled)return;button.disabled=true;button.textContent='Gemmer godkendelse…';try{const response=await fetch(`/api/v1/plans/${currentPlan.id}/approve`,{method:'POST',headers:{'X-Solportal-Token':currentPlan.csrf_token,'Content-Type':'application/json'},body:'{}'});const result=await response.json();if(!response.ok)throw new Error(result.error||'Godkendelsen fejlede');button.textContent='Plan gemt og godkendt';button.classList.add('approved');document.querySelector('#apply-plan').disabled=false;document.querySelector('#approval-title').textContent='Godkendt plan';document.querySelector('#approval-copy').textContent=`Gyldig til ${new Date(result.expires_at).toLocaleString('da-DK')}. Du kan nu anvende den manuelt på inverteren.`}catch(error){button.disabled=false;button.textContent='Prøv godkendelse igen';document.querySelector('#approval-copy').textContent=error.message}}

async function applyPlan(){const button=document.querySelector('#apply-plan');if(!currentPlan||button.disabled)return;button.disabled=true;button.textContent='Sender til inverter…';try{const response=await fetch(`/api/v1/plans/${currentPlan.id}/apply`,{method:'POST',headers:{'X-Solportal-Token':currentPlan.csrf_token,'Content-Type':'application/json'},body:'{}'});const result=await response.json();if(!response.ok)throw new Error(result.error||'Planen kunne ikke sendes');document.querySelector('#approval-copy').textContent=`Kommando #${result.command_id} er sendt til den lokale worker. Ingen automatisk replanning.`;setTimeout(refreshPlan,7000)}catch(error){button.disabled=false;button.textContent='Prøv anvendelse igen';document.querySelector('#approval-copy').textContent=error.message}}

async function refreshInsights() {
    try {
        const response = await fetch('/api/v1/insights', {cache:'no-store'}); if (!response.ok) return;
        const data = await response.json(), weather = data.weather || [], prices = data.prices || [];
        const score = Number(data.solar_barometer || 0); document.querySelector('#solar-score').textContent = Math.round(score); document.querySelector('#solar-dial').style.setProperty('--score', score);
        document.querySelector('#solar-label').textContent = score >= 70 ? 'Stærk soldag på vej' : score >= 40 ? 'En blandet soldag' : 'Begrænset sol i prognosen';
        document.querySelector('#weather-strip').innerHTML = weather.filter((_,i)=>i%6===0).slice(0,4).map(item => `<div class="weather-hour"><b>${new Date(item.forecast_at+'Z').toLocaleString('da-DK',{weekday:'short',hour:'2-digit'})}</b><i>${weatherIcon(item.symbol_code)}</i><small>${Number(item.temperature_c).toFixed(0)}° · ${Number(item.cloud_pct).toFixed(0)}% sky</small></div>`).join('');
        if (prices.length) { const now=prices[0], low=prices.reduce((a,b)=>Number(a.total_dkk_kwh)<Number(b.total_dkk_kwh)?a:b), high=prices.reduce((a,b)=>Number(a.total_dkk_kwh)>Number(b.total_dkk_kwh)?a:b); const price=value=>`${Number(value).toLocaleString('da-DK',{minimumFractionDigits:2,maximumFractionDigits:2})} kr.`; document.querySelector('#price-now').textContent=price(now.total_dkk_kwh); document.querySelector('#price-low').textContent=price(low.total_dkk_kwh); document.querySelector('#price-high').textContent=price(high.total_dkk_kwh); drawPrice(prices); }
        document.querySelector('#plan-action').textContent = data.plan?.action || 'Afventer datagrundlag.';
        document.querySelector('#plan-timeline').innerHTML = (data.plan?.cheap_intervals || []).slice(0,3).map(slot => `<div class="time-slot"><i></i><div><b>${new Date(slot.interval_start+'Z').toLocaleString('da-DK',{weekday:'short',hour:'2-digit',minute:'2-digit'})}</b><small>Muligt ladevindue · ${Number(slot.total_dkk_kwh).toLocaleString('da-DK',{minimumFractionDigits:2,maximumFractionDigits:2})} kr./kWh</small></div></div>`).join('');
        const event = (data.consumption_events || [])[0]; if (event) document.querySelector('#consumption-status').textContent = `Senest: ${(Number(event.detected_load_w)/1000).toLocaleString('da-DK',{maximumFractionDigits:1})} kW · ${Number(event.energy_kwh).toLocaleString('da-DK',{maximumFractionDigits:1})} kWh · ${Math.round(Number(event.confidence)*100)}% sikker`;
    } catch { /* Device dashboard remains usable without external feeds. */ }
}

function describe(state) {
    const batteryText = state.battery_power_w > 50 ? 'Batteriet hjælper huset' : state.battery_power_w < -50 ? 'Overskuddet lagres' : 'Batteriet er i hvile';
    const gridText = state.grid_power_w > 50 ? 'Der hentes energi fra nettet' : state.grid_power_w < -50 ? 'Overskud sendes til nettet' : 'Næsten intet netflow';
    const soc = Number(state.battery_soc_pct || 0);
    document.querySelector('#battery-state').textContent = batteryText;
    document.querySelector('#insight-title').textContent = batteryText;
    document.querySelector('#grid-state').textContent = gridText;
    document.querySelector('#grid-direction').textContent = state.grid_power_w >= 0 ? 'Importerer' : 'Eksporterer';
    document.querySelector('#soc-big').textContent = `${soc.toLocaleString('da-DK', {maximumFractionDigits: 0})} %`;
    document.querySelector('#flow-soc').textContent = `${soc.toLocaleString('da-DK', {maximumFractionDigits: 0})} %`;
    document.querySelector('.soc-bar').style.setProperty('--soc', `${soc}%`);
    document.querySelector('#battery-fill').style.setProperty('--soc', `${soc}%`);
    document.querySelector('#flow-grid-label').textContent = state.grid_power_w >= 0 ? 'import' : 'eksport';
}

function animateFlows(state) {
    const flows = [
        ['#flow-solar', state.pv_power_w, false],
        ['#flow-home', state.load_power_w, false],
        ['#flow-battery', state.battery_power_w, state.battery_power_w > 0],
        ['#flow-grid', state.grid_power_w, state.grid_power_w > 0],
    ];
    flows.forEach(([selector, value, reverse]) => {
        const line = document.querySelector(selector);
        line.classList.toggle('idle', Math.abs(Number(value) || 0) < 35);
        line.classList.toggle('reverse', reverse);
        line.style.animationDuration = `${Math.max(.55, 2.1 - Math.min(Math.abs(Number(value) || 0), 6000) / 4000)}s`;
    });
}

async function refresh() {
    try {
        const response = await fetch('/api/v1/state', {cache: 'no-store'});
        const payload = await response.json();
        const state = payload.data || {};
        document.querySelector('#pv').textContent = fmt(state.pv_power_w);
        document.querySelector('#load').textContent = fmt(state.load_power_w);
        document.querySelector('#battery-flow-label').textContent = fmt(state.battery_power_w);
        document.querySelector('#grid').textContent = fmt(state.grid_power_w);
        document.querySelector('#flow-pv').textContent = fmt(state.pv_power_w);
        document.querySelector('#flow-load').textContent = fmt(state.load_power_w);
        document.querySelector('#flow-battery-value').textContent = fmt(state.battery_power_w);
        document.querySelector('#flow-grid-value').textContent = fmt(state.grid_power_w);
        document.querySelector('#updated-time').textContent = new Date().toLocaleTimeString('da-DK', {hour: '2-digit', minute: '2-digit', second: '2-digit'});
        const modeName={load_first:'Load First',battery_first:'Battery First',grid_first:'Grid First',unknown:'Ukendt mode'}[state.priority_mode]||'Mode afventer';document.querySelector('#inverter-state').textContent=`${modeName} · VPP`;
        describe(state);
        animateFlows(state);
        const fresh = payload.data_age_seconds !== null && payload.data_age_seconds < 30;
        document.body.dataset.offline = fresh ? 'false' : 'true';
        document.querySelector('#connection-status').textContent = fresh ? 'Forbundet' : `Data er ${payload.data_age_seconds ?? '?'} sek. gamle`;
        samples.push({pv: Number(state.pv_power_w) || 0, load: Number(state.load_power_w) || 0, battery: Number(state.battery_power_w) || 0, grid: Number(state.grid_power_w) || 0});
        if (samples.length > 72) samples.shift();
        document.querySelector('#sample-count').textContent = `${samples.length} målepunkter`;
        draw();
    } catch {
        document.body.dataset.offline = 'true';
        document.querySelector('#connection-status').textContent = 'Forbindelsesfejl';
    }
}

clock();
setInterval(clock, 1000);
refresh();
setInterval(refresh, 5000);
refreshInsights();
setInterval(refreshInsights, 15 * 60 * 1000);
refreshPlan();
setInterval(refreshPlan, 15 * 60 * 1000);
document.querySelector('#approve-plan')?.addEventListener('click',approvePlan);
document.querySelector('#apply-plan')?.addEventListener('click',applyPlan);
addEventListener('resize', draw);
if ('serviceWorker' in navigator) navigator.serviceWorker.register('/service-worker.js');
