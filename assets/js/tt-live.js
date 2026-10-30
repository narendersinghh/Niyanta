/* Top-bar live tracking chip: ticks every second and checks every 2 minutes whether tracking is still running. */
(()=>{"use strict";const c=document.querySelector("[data-tt-live]");if(!c)return;const k=c.querySelector("[data-tt-clock]"),p=n=>String(n).padStart(2,"0");let b=+c.dataset.base||0,t=Date.now();
const f=s=>{s=Math.max(0,Math.floor(s));return Math.floor(s/3600)+":"+p(Math.floor(s%3600/60))+":"+p(s%60)};setInterval(()=>{k.textContent=f(b+(Date.now()-t)/1e3)},1000);
setInterval(async()=>{if(document.hidden||!window.Niyanta)return;try{const j=await Niyanta.req("time/status");if(j.state!=="tracking"){c.remove();return}b=j.running.elapsed;t=Date.now();const w=c.querySelector(".tt-what");if(w&&j.projects)w.textContent=j.running.project||"No project"}catch(e){}},120000)})();
