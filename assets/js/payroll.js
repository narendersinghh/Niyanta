/* Payroll: compensation form (monthly vs hourly labels); payslip edit form (manual lines, live server-side totals); bulk line picker */
(()=>{"use strict";const d=document,N=window.Niyanta;
d.querySelectorAll("[data-pay-type]").forEach(sel=>{const f=sel.form;if(!f)return;const sync=()=>{const h=sel.value==="hourly";f.querySelectorAll("[data-pay-label]").forEach(el=>el.classList.toggle("hide",el.dataset.payLabel!==(h?"hourly":"monthly")));const u=f.querySelector("[data-pay-unit]");if(u)u.textContent=h?"per hour":"per month"};sel.addEventListener("change",sync);sync()});
d.querySelectorAll("[data-pay-picks]").forEach(p=>{const all=p.querySelector("[data-pay-all]");if(all)all.addEventListener("change",()=>p.querySelectorAll("input[name='slips[]']").forEach(c=>{c.checked=all.checked}))});
const f=d.querySelector("[data-pay-edit]");if(!f||!N)return;
const live=d.querySelector("[data-pay-live]"),L=k=>live&&live.querySelector('[data-live="'+k+'"]'),dirty=d.querySelector("[data-pay-dirty]");
let next=+f.dataset.next||0,t=null,seq=0,changed=false;
const run=async()=>{const my=++seq;try{const j=await N.req(f.dataset.preview,{body:new FormData(f)});if(my!==seq)return;
["gross","deductions","net"].forEach(k=>{const el=L(k);if(el)el.textContent=j[k]});
const dl=L("delta");if(dl)dl.textContent=j.delta?j.deltaText+" vs saved":"";const fl=L("flag");if(fl)fl.classList.toggle("hide",!j.flagged);const er=L("error");if(er)er.textContent="";
const c=d.querySelector("[data-pay-cover]");if(c){c.classList.toggle("hide",!j.cover);const s=c.querySelector("span");if(s)s.textContent=j.cover||""}}
catch(e){const er=L("error");if(er)er.textContent=(e.data&&e.data.message)||e.message}};
const queue=()=>{changed=true;if(dirty)dirty.classList.remove("hide");clearTimeout(t);t=setTimeout(run,350)};
f.addEventListener("input",queue);f.addEventListener("change",e=>{const r=e.target.closest("[data-pay-remove]");if(r)r.closest("tr").classList.toggle("pay-removed",r.checked);queue()});
f.addEventListener("click",e=>{const b=e.target.closest("[data-pay-new]");if(b){const card=b.closest(".card"),kind=b.closest("[data-pay-add]").dataset.payAdd,tpl=d.querySelector('[data-pay-tpl="'+kind+'"]');if(!tpl||!card)return;
const tb=card.querySelector("tbody"),em=tb.querySelector("[data-pay-empty]");if(em)em.remove();
const w=d.createElement("tbody");w.innerHTML=tpl.innerHTML.replace(/__i__/g,String(next++));const row=w.firstElementChild;tb.append(row);
const sel=row.querySelector("select");if(sel)sel.value=b.dataset.payNew;const nm=row.querySelector("input[name$='[name]']");if(nm){nm.placeholder=sel?sel.options[sel.selectedIndex].text:"";nm.focus()}queue();return}
const x=e.target.closest("[data-pay-del]");if(x){x.closest("tr").remove();queue()}});
f.addEventListener("submit",()=>{changed=false});
window.addEventListener("beforeunload",e=>{if(changed){e.preventDefault();e.returnValue=""}});
})();
