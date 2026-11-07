/* Leave type dialog (show only the fields for the chosen allowance radio / carry rule / paid toggle), busy state for slow AI forms */
(()=>{"use strict";const d=document;
const sync=f=>{const p=f.querySelector("[data-lv-paid]"),a=f.querySelector("[data-lv-allowance]:checked")||f.querySelector("select[data-lv-allowance]"),c=f.querySelector("[data-lv-carry]"),paid=!p||p.checked;
f.querySelectorAll('[data-lv-when="paid"]').forEach(x=>x.hidden=!paid);f.querySelectorAll("[data-lv-show]").forEach(x=>x.hidden=!a||a.value!==x.dataset.lvShow);f.querySelectorAll("[data-lv-carry-show]").forEach(x=>x.hidden=!c||c.value!==x.dataset.lvCarryShow)};
d.querySelectorAll("[data-lv-type]").forEach(f=>{sync(f);f.addEventListener("change",()=>sync(f))});
d.addEventListener("submit",e=>{const f=e.target.closest("form[data-busy]");if(!f)return;setTimeout(()=>{if(e.defaultPrevented)return;f.querySelectorAll("button").forEach(b=>{b.disabled=true});const b=f.querySelector("button");if(b)b.lastChild.textContent=" "+f.dataset.busy},0)});
})();
