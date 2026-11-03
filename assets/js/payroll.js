/* Payroll: compensation revision form switches the amount label, unit and hint between monthly salary and hourly rate */
(()=>{"use strict";const d=document;
d.querySelectorAll("[data-pay-type]").forEach(sel=>{const f=sel.form;if(!f)return;const sync=()=>{const h=sel.value==="hourly";f.querySelectorAll("[data-pay-label]").forEach(el=>el.classList.toggle("hide",el.dataset.payLabel!==(h?"hourly":"monthly")));const u=f.querySelector("[data-pay-unit]");if(u)u.textContent=h?"per hour":"per month"};sel.addEventListener("change",sync);sync()});
})();
