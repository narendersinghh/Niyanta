/* Background time-tracker heartbeat on pages without the timer card, so running timers are not auto-stopped while the user works elsewhere in the app. */
(()=>{"use strict";const d=document,N=window.Niyanta;if(!N||d.querySelector("[data-running]"))return;
const k="ny_tr",now=()=>Date.now();let st={};try{st=JSON.parse(sessionStorage.getItem(k)||"{}")}catch(e){}
if(st.r===0&&now()-(st.t||0)<300000)return;
let last=now(),act=now(),a=0,i=0;const idleMs=300000;
["mousemove","keydown","scroll","click","touchstart"].forEach(e=>d.addEventListener(e,()=>{act=now()},{passive:true}));
const tick=()=>{const t=now(),s=Math.round((t-last)/1000);last=t;if(t-act>idleMs||d.hidden)i+=s;else a+=s};
const beat=async()=>{tick();try{const j=await N.req("time/heartbeat",{body:{active:a,idle:i}});a=0;i=0;try{sessionStorage.setItem(k,JSON.stringify({r:j.running?1:0,t:now()}))}catch(e){}if(!j.running)clearInterval(h)}catch(e){if(e.status===403||e.status===401)clearInterval(h)}};
const h=setInterval(beat,60000);beat()})();
