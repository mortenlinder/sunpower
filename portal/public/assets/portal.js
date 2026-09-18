'use strict';
const $=s=>document.querySelector(s);
const fmt=(v,n=1)=>v==null?'—':Number(v).toLocaleString('da-DK',{minimumFractionDigits:n,maximumFractionDigits:n});
const labels={load_first:'Load First',battery_first:'Battery First',grid_first:'Grid First',charge_grid:'Oplad fra net'};
const time=t=>new Date(t*1000).toLocaleString('da-DK',{timeZone:'Europe/Copenhagen',day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'});
async function api(query){const r=await fetch('/?'+new URLSearchParams(query),{cache:'no-store'});if(!r.ok)throw new Error('Kunne ikke hente data');return r.json()}
function svgNode(tag,attrs={},text=''){const n=document.createElementNS('http://www.w3.org/2000/svg',tag);Object.entries(attrs).forEach(([k,v])=>n.setAttribute(k,String(v)));if(text)n.textContent=text;return n}
const chartData=new Map();
function chart(el,rows,series,unit,step=1800){
 if(!el)return;chartData.set(el,[rows,series,unit,step]);el.replaceChildren();const w=Math.max(280,Math.round(el.getBoundingClientRect().width)),h=el.classList.contains('large')?300:230;el.setAttribute('viewBox',`0 0 ${w} ${h}`);const left=48,right=w-12,top=18,bottom=h-36;
 if(!rows.length){el.append(svgNode('text',{x:w/2,y:h/2,'text-anchor':'middle'},'Ingen målinger at vise endnu'));return}
 const vals=rows.flatMap(r=>series.map(s=>r[s.key]==null?null:Number(r[s.key])*s.scale)).filter(v=>v!=null&&Number.isFinite(v));let lo=Math.min(0,...vals),hi=Math.max(0.1,...vals);if(hi===lo)hi=lo+1;
 const start=Number(rows[0].t),end=Math.max(start+step,Number(rows[rows.length-1].t));const x=t=>left+(Number(t)-start)/(end-start)*(right-left),y=v=>bottom-(v-lo)/(hi-lo)*(bottom-top);
 for(let i=0;i<=4;i++){const v=lo+(hi-lo)*i/4,yy=y(v);el.append(svgNode('line',{x1:left,x2:right,y1:yy,y2:yy,class:'gridline'}));el.append(svgNode('text',{x:left-9,y:yy+4,'text-anchor':'end'},fmt(v,1)))}
 el.append(svgNode('text',{x:4,y:12},unit));
 const ticks=w<450?2:3;
 for(let i=0;i<ticks;i++){const t=start+(end-start)*i/(ticks-1);el.append(svgNode('text',{x:x(t),y:h-8,'text-anchor':i===0?'start':i===ticks-1?'end':'middle'},time(t)))}
 for(const s of series){let d='',prev=null;for(const r of rows){const v=r[s.key];if(v==null){prev=null;continue}const gap=prev===null||Number(r.t)-prev>step*1.6;d+=(gap?'M':'L')+x(r.t).toFixed(2)+','+y(Number(v)*s.scale).toFixed(2)+' ';prev=Number(r.t)}el.append(svgNode('path',{d,fill:'none',stroke:s.color,'stroke-width':2.5,'stroke-linejoin':'round'}))}
}
let resizeTimer;window.addEventListener('resize',()=>{clearTimeout(resizeTimer);resizeTimer=setTimeout(()=>{for(const [el,args] of chartData)chart(el,...args)},150)});
const token=$('#link-token');if(token){const value=new URLSearchParams(location.hash.slice(1)).get('token');if(value&&/^[a-f0-9]{64}$/.test(value))token.value=value;history.replaceState(null,'',location.pathname+location.search)}
if($('#community-total')){
 api({api:'public'}).then(d=>{const count=d.points.length;$('#community-total').textContent=fmt(d.today_kwh,1);$('#community-status').textContent=count?'Produktionen i de viste intervaller, fra fællesskabets delte målinger.':'Vi venter på mindst '+d.minimum+' anlæg med frivillig deling i samme interval. Ingen kunstige tal.';chart($('#community-chart'),d.points,[{key:'kwh',scale:1,color:'#bde97a'}],'kWh');}).catch(()=>{$('#community-status').textContent='Fællesskabets tal kan ikke hentes lige nu.';});
}
const device=$('#device');if(device){
 const id=device.dataset.id;let lastSample=null;
 function age(){if(!lastSample)return;const seconds=Math.max(0,Math.floor(Date.now()/1000-lastSample));$('#freshness').textContent=seconds>60?'Data er '+seconds+' sek. gamle':'Opdateret '+time(lastSample);$('#freshness').classList.toggle('stale',seconds>60)}
 async function refresh(){try{const d=await api({api:'state',id}),s=d.state;lastSample=d.sample_at;$('#pv').textContent=fmt(s.pv_power_w==null?null:s.pv_power_w/1000,2)+' kW';$('#load').textContent=fmt(s.load_power_w==null?null:s.load_power_w/1000,2)+' kW';$('#soc').textContent=fmt(s.battery_soc_pct,0)+' %';$('#battery').textContent=fmt(s.battery_power_w==null?null:s.battery_power_w/1000,2)+' kW · + afladning / − opladning';$('#grid').textContent=fmt(s.grid_power_w==null?null:s.grid_power_w/1000,2)+' kW';$('#mode').textContent=labels[s.priority_mode]||'Ukendt';$('#override-status').textContent=s.override_until>Date.now()/1000?'Midlertidig mode frem til '+time(s.override_until):'Ingen aktiv fjernoverstyring.';$('#commands').replaceChildren();if(!d.commands.length)$('#commands').textContent='Ingen kommandoer endnu.';for(const c of d.commands){const item=document.createElement('div');item.className='command';const strong=document.createElement('strong');strong.textContent=(labels[c.mode]||c.mode)+' · '+({pending:'Afventer Pi',verified:'Verificeret på inverter',failed:'Fejlet',cancelled:'Annulleret',expired:'Udløbet uden svar'}[c.status]||c.status);const small=document.createElement('small');small.textContent=c.created_at+' UTC · '+c.duration_minutes+' min.';item.append(strong,small);if(c.result_json){const r=JSON.parse(c.result_json);if(r.message){const p=document.createElement('small');p.textContent=r.message;item.append(p)}}$('#commands').append(item)}age();}catch{$('#freshness').textContent='Forbindelsen til portalen fejler — tal opdateres ikke';$('#freshness').classList.add('stale')}}
 async function history(){try{const d=await api({api:'history',id,days:$('#history-days').value});chart($('#history-chart'),d.points,[{key:'pv',scale:.001,color:'#c89c27'},{key:'load_w',scale:.001,color:'#359366'},{key:'grid_w',scale:.001,color:'#4884bf'},{key:'battery',scale:.001,color:'#9265b6'}],'kW',d.step);$('#history-info').textContent=d.points.length+' intervaller · gennemsnit af modtagne målinger · dansk tid. Huller er ikke nulforbrug.';}catch{$('#history-info').textContent='Historikken kunne ikke hentes.'}}
 refresh();history();setInterval(()=>{if(!document.hidden)refresh()},15000);setInterval(age,5000);setInterval(()=>{if(!document.hidden)history()},60000);$('#history-days').addEventListener('change',history);
}
