/* HR module: leave day preview, reveal masked bank/tax values */
(()=>{"use strict";const d=document,N=window.Niyanta;
const f=d.querySelector("[data-leave-form]");if(f&&N){const out=f.querySelector("[data-leave-preview]"),s=f.elements.start_date,e=f.elements.end_date,h=f.elements.half_day;let t;
const run=()=>{clearTimeout(t);t=setTimeout(async()=>{if(!s.value){out.hidden=true;return}if(h.checked)e.value=s.value;if(e.value&&e.value<s.value)e.value=s.value;
const q=new URLSearchParams({from:s.value,to:e.value||s.value,half:h.checked?1:0});try{const j=await N.req(f.dataset.preview+"?"+q);out.hidden=false;out.textContent=j.note;out.classList.toggle("warn",!j.days)}catch(err){out.hidden=true}},200)};
[s,e,h].forEach(x=>x&&x.addEventListener("change",run));if(s.value)run()}
d.addEventListener("click",async ev=>{const b=ev.target.closest("[data-reveal]");if(!b||!N)return;ev.preventDefault();b.disabled=true;
try{const j=await N.req(b.dataset.reveal,{body:{}});[["bank",j.bank],["tax",j.tax]].forEach(([k,v])=>{const el=d.querySelector(`[data-secret="${k}"]`);if(el&&v)el.textContent=v});b.remove();N.toast("Revealed — this view was logged")}catch(err){b.disabled=false;N.toast(err.message,"error")}});
})();
