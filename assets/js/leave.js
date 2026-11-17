/* Leave type dialog (show only the fields for the chosen allowance radio / carry rule / paid toggle), busy state for slow AI forms, approver edit / revoke / cancellation dialogs filled from the row */
(()=>{"use strict";const d=document;
const sync=f=>{const p=f.querySelector("[data-lv-paid]"),a=f.querySelector("[data-lv-allowance]:checked")||f.querySelector("select[data-lv-allowance]"),c=f.querySelector("[data-lv-carry]"),paid=!p||p.checked;
f.querySelectorAll('[data-lv-when="paid"]').forEach(x=>x.hidden=!paid);f.querySelectorAll("[data-lv-show]").forEach(x=>x.hidden=!a||a.value!==x.dataset.lvShow);f.querySelectorAll("[data-lv-carry-show]").forEach(x=>x.hidden=!c||c.value!==x.dataset.lvCarryShow)};
d.querySelectorAll("[data-lv-type]").forEach(f=>{sync(f);f.addEventListener("change",()=>sync(f))});
d.addEventListener("submit",e=>{const f=e.target.closest("form[data-busy]");if(!f)return;setTimeout(()=>{if(e.defaultPrevented)return;f.querySelectorAll("button").forEach(b=>{b.disabled=true});const b=f.querySelector("button");if(b)b.lastChild.textContent=" "+f.dataset.busy},0)});
d.addEventListener("click",e=>{const b=e.target.closest("[data-lv-edit],[data-lv-act]");if(!b)return;const m=d.getElementById(b.dataset.modal),f=m&&m.querySelector("form");if(!f)return;let r={};try{r=JSON.parse(b.dataset.lv||"{}")}catch(x){}
const s=m.querySelector("[data-lv-summary]");if(s)s.textContent=r.summary||"";const rs=m.querySelector("[data-lv-reason]");if(rs)rs.textContent=r.cancel_reason||"No reason given.";
const nm=m.querySelector("[data-lv-name]");if(nm)nm.textContent=r.name||"";const wy=m.querySelector("[data-lv-why]");if(wy)wy.textContent=r.reason||"No reason given.";const bl=m.querySelector("[data-lv-balline]");if(bl){bl.textContent=r.balance||"";bl.hidden=!r.balance}
f.reset();m.querySelectorAll(".field-error").forEach(x=>x.remove());f.action=b.dataset.lvEdit||b.dataset.lvAct;if(!b.dataset.lvEdit)return;
const set=(n,v)=>{const el=f.elements[n];if(el)el.value=v==null?"":v};set("lv_id",r.id);set("user_id",r.user_id);set("lv_summary",r.summary);set("leave_type_id",r.leave_type_id);set("start_date",r.start_date);set("end_date",r.end_date);set("reason",r.reason);
if(f.elements.half_day)f.elements.half_day.checked=!!r.half_day;f.elements.start_date.dispatchEvent(new Event("change"))});
/* reject needs a reason (single review dialog and the bulk bar) */
d.addEventListener("click",e=>{const b=e.target.closest("[data-lv-need-note]");if(!b||!b.form)return;const n=b.form.elements.review_note;if(n&&!n.value.trim()){e.preventDefault();e.stopImmediatePropagation();n.setCustomValidity("Give a reason for rejecting. The employee sees it.");n.reportValidity();n.focus();n.addEventListener("input",()=>n.setCustomValidity(""),{once:true})}},true);
/* bulk approve / reject: select pending requests on the page */
const bk=d.querySelector("[data-lv-bulk]");if(bk){const picks=()=>[...d.querySelectorAll("[data-lv-pick]")],all=bk.querySelector("[data-lv-all]"),cnt=bk.querySelector("[data-lv-count]");
const upd=()=>{const n=picks().filter(x=>x.checked).length;cnt.textContent=n?n+" selected":"Select all";all.checked=n>0&&n===picks().length;all.indeterminate=n>0&&n<picks().length;bk.classList.toggle("on",n>0);bk.querySelectorAll("button").forEach(b=>{b.disabled=!n;if(b.value==="approve")b.dataset.confirm="Approve "+n+" leave request"+(n===1?"":"s")+"? Each person is notified."})};
all.addEventListener("change",()=>{picks().forEach(x=>{x.checked=all.checked});upd()});d.addEventListener("change",e=>{if(e.target.matches("[data-lv-pick]"))upd()});upd()}
})();
