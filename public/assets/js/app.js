'use strict';

const fmt = value => `${(Math.abs(Number(value) || 0) / 1000).toLocaleString('da-DK', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
})} kW`;
const samples = [];

function clock() {
    const element = document.querySelector('#clock');
    if (element) element.textContent = new Date().toLocaleString('da-DK', {hour: '2-digit', minute: '2-digit'});
}

function draw() {
    const canvas = document.querySelector('#chart');
    if (!canvas) return;
    const ratio = devicePixelRatio || 1;
    const width = canvas.clientWidth;
    const height = 170;
    if (canvas.width !== Math.round(width * ratio)) {
        canvas.width = Math.round(width * ratio);
        canvas.height = Math.round(height * ratio);
    }
    const context = canvas.getContext('2d');
    context.setTransform(ratio, 0, 0, ratio, 0, 0);
    context.clearRect(0, 0, width, height);
    context.strokeStyle = '#234238';
    context.lineWidth = 1;
    for (let row = 0; row < 4; row++) {
        context.beginPath();
        context.moveTo(0, 10 + row * 48);
        context.lineTo(width, 10 + row * 48);
        context.stroke();
    }
    const maximum = Math.max(1000, ...samples.flatMap(sample => [sample.pv, sample.load, Math.abs(sample.battery), Math.abs(sample.grid)]));
    for (const [key, color] of [['pv', '#f5c85b'], ['load', '#56e39f'], ['battery', '#b6eb55'], ['grid', '#65a9ff']]) {
        context.strokeStyle = color;
        context.lineWidth = 2;
        context.beginPath();
        samples.forEach((sample, index) => {
            const x = samples.length < 2 ? 0 : index * width / (samples.length - 1);
            const y = 160 - (Math.abs(sample[key]) / maximum) * 145;
            index ? context.lineTo(x, y) : context.moveTo(x, y);
        });
        context.stroke();
    }
}

function describe(state) {
    document.querySelector('#battery-state').textContent = state.battery_power_w > 50 ? 'Batteriet aflader til huset' : state.battery_power_w < -50 ? 'Batteriet oplades' : 'Batteriet er i hvile';
    document.querySelector('#grid-state').textContent = state.grid_power_w > 50 ? 'Der importeres fra elnettet' : state.grid_power_w < -50 ? 'Der eksporteres til elnettet' : 'Næsten intet netflow';
    document.querySelector('#grid-direction').textContent = state.grid_power_w >= 0 ? 'Import' : 'Eksport';
    document.querySelector('#soc').textContent = `${Number(state.battery_soc_pct || 0).toLocaleString('da-DK', {maximumFractionDigits: 0})} % SOC`;
}

async function refresh() {
    try {
        const response = await fetch('/api/v1/state', {cache: 'no-store'});
        const payload = await response.json();
        const state = payload.data || {};
        document.querySelector('#pv').textContent = fmt(state.pv_power_w);
        document.querySelector('#load').textContent = fmt(state.load_power_w);
        document.querySelector('#battery').textContent = fmt(state.battery_power_w);
        document.querySelector('#grid').textContent = fmt(state.grid_power_w);
        describe(state);
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
addEventListener('resize', draw);
if ('serviceWorker' in navigator) navigator.serviceWorker.register('/service-worker.js');
