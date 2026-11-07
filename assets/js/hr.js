/* HR module: leave day preview, reveal masked bank/tax values, default/custom schedule toggles */
(()=>{"use strict";const d=document,N=window.Niyanta;
d.querySelectorAll("[data-leave-form]").forEach(f=>{if(!N)return;const out=f.querySelector("[data-leave-preview]"),s=f.elements.start_date,e=f.elements.end_date,h=f.elements.half_day;let t;
const run=()=>{clearTimeout(t);t=setTimeout(async()=>{if(!s.value){out.hidden=true;return}if(h&&h.checked)e.value=s.value;if(e.value&&e.value<s.value)e.value=s.value;
const q=new URLSearchParams({from:s.value,to:e.value||s.value,half:h&&h.checked?1:0});try{const j=await N.req(f.dataset.preview+"?"+q);out.hidden=false;out.textContent=j.note;out.classList.toggle("warn",!j.days)}catch(err){out.hidden=true}},200)};
[s,e,h].forEach(x=>x&&x.addEventListener("change",run));if(s.value)run()});
const mode=g=>{const v=(g.querySelector("input:checked")||{}).value;d.querySelectorAll(`[data-mode-panel="${g.dataset.mode}"]`).forEach(p=>{p.hidden=v!=="custom";p.querySelectorAll("input").forEach(i=>i.disabled=v!=="custom")});d.querySelectorAll(`[data-mode-default="${g.dataset.mode}"]`).forEach(p=>p.hidden=v==="custom")};
d.querySelectorAll("[data-mode]").forEach(g=>{mode(g);g.addEventListener("change",()=>mode(g))});
d.addEventListener("click",async ev=>{const b=ev.target.closest("[data-reveal]");if(!b||!N)return;ev.preventDefault();b.disabled=true;
try{const j=await N.req(b.dataset.reveal,{body:{}});[["bank",j.bank],["tax",j.tax]].forEach(([k,v])=>{const el=d.querySelector(`[data-secret="${k}"]`);if(el&&v)el.textContent=v});b.remove();N.toast("Revealed, this view was logged")}catch(err){b.disabled=false;N.toast(err.message,"error")}});
/* edit HR profile: show the custom days-off picker only for a custom work week */
d.querySelectorAll("[data-days-mode]").forEach(sel=>{const box=d.querySelector("[data-days-custom]");if(!box)return;sel.addEventListener("change",()=>{box.hidden=sel.value!=="custom"})});
/* onboarding & exit: close an open reject / deletion popover when clicking elsewhere */
d.addEventListener("click",ev=>{d.querySelectorAll("details.hr-pop[open]").forEach(x=>{if(!x.contains(ev.target)&&!x.classList.contains("block"))x.open=false})});
})();
