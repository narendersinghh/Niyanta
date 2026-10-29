/* Projects bundle: Kanban drag & drop + inline quick add, quick done toggles, collapsible groups, member filter, file labels, work report prefill */
(()=>{"use strict";const d=document,$=(s,c=d)=>c.querySelector(s),$$=(s,c=d)=>[...c.querySelectorAll(s)],N=window.Niyanta;
/* Kanban: HTML5 DnD; the server validates permission + dependencies and we revert on error */
const board=$("[data-board]");
if(board){let drag=null,origin=null,next=null;
const counts=()=>$$(".kcol",board).forEach(c=>{const n=$$(".kcard",c).length,el=$("[data-count]",c),e=$(".kempty",c),w=$("[data-wip]",c);if(el)el.textContent=n;if(e)e.classList.toggle("hide",n>0);if(w){$("[data-wip-n]",w).textContent=n;w.classList.toggle("over",n>+w.dataset.wip)}});
const after=(col,y)=>$$(".kcard:not(.dragging)",col).find(c=>{const r=c.getBoundingClientRect();return y<r.top+r.height/2})||null;
board.addEventListener("dragstart",e=>{const c=e.target.closest(".kcard[draggable=true]");if(!c){e.preventDefault();return}drag=c;origin=c.parentElement;next=c.nextElementSibling;c.classList.add("dragging");e.dataTransfer.effectAllowed="move";e.dataTransfer.setData("text/plain",c.dataset.id)});
board.addEventListener("dragend",()=>{if(drag)drag.classList.remove("dragging");$$(".kcol.over",board).forEach(c=>c.classList.remove("over"))});
board.addEventListener("dragover",e=>{const col=e.target.closest(".kcol");if(!col||!drag)return;e.preventDefault();e.dataTransfer.dropEffect="move";$$(".kcol.over",board).forEach(c=>c!==col&&c.classList.remove("over"));col.classList.add("over");const drop=$(".kdrop",col),ref=after(drop,e.clientY);drop.insertBefore(drag,ref||$(".kempty",drop))});
board.addEventListener("drop",async e=>{const col=e.target.closest(".kcol");if(!col||!drag)return;e.preventDefault();col.classList.remove("over");const card=drag,from=origin,sib=next;drag=null;card.classList.remove("dragging");counts();
if(from===card.parentElement&&sib===card.nextElementSibling)return;
const status=col.dataset.status,order=$$(".kcard",col).map(c=>c.dataset.id).join(",");card.classList.add("saving");
try{const j=await N.req("tasks/"+card.dataset.id+"/move",{body:{status,order}});N.toast(j.message||"Saved")}
catch(err){from.insertBefore(card,sib&&sib.parentElement===from?sib:$(".kempty",from));counts();N.toast(err.message,"error")}
finally{card.classList.remove("saving")}});
/* inline quick add at the bottom of a column */
board.addEventListener("click",e=>{const o=e.target.closest("[data-kadd-open]"),c=e.target.closest("[data-kadd-close]");if(o){const k=o.closest("[data-kadd]");$$("[data-kadd].open",board).forEach(x=>x!==k&&x.classList.remove("open"));k.classList.add("open");$("textarea",k).focus()}if(c){const k=c.closest("[data-kadd]");k.classList.remove("open");$("textarea",k).value=""}});
board.addEventListener("keydown",e=>{const t=e.target.closest("[data-kadd-form] textarea");if(!t)return;if(e.key==="Enter"&&!e.shiftKey){e.preventDefault();t.form.requestSubmit()}if(e.key==="Escape"){t.value="";t.closest("[data-kadd]").classList.remove("open")}});
board.addEventListener("submit",async e=>{const f=e.target.closest("[data-kadd-form]");if(!f)return;e.preventDefault();const t=$("textarea",f),title=t.value.trim();if(!title||f.dataset.busy)return;f.dataset.busy="1";
try{const j=await N.req("projects/"+board.dataset.project+"/tasks",{body:{title,status:f.dataset.status,priority:"medium",billable:board.dataset.billable}});const drop=$(".kdrop",f.closest(".kcol")),w=d.createElement("div");w.innerHTML=j.html.trim();const card=w.firstElementChild;card.classList.add("is-new");drop.insertBefore(card,$(".kempty",drop));t.value="";counts();N.toast(j.message||"Task added")}
catch(err){N.toast(err.message,"error")}finally{delete f.dataset.busy;t.focus()}});
counts()}
/* "Add task" buttons preset the status of the new-task modal */
d.addEventListener("click",e=>{const b=e.target.closest('[data-modal="task-new"][data-status]');if(!b)return;const s=$("#task-new [data-task-status]");if(s)s.value=b.dataset.status});
/* quick done toggle (My Tasks, widgets): AJAX status change, form fallback without JS */
d.addEventListener("submit",async e=>{const f=e.target.closest("form[data-quick-done]");if(!f)return;e.preventDefault();const row=f.closest("[data-task-row]"),b=$("button",f),inp=$("[name=status]",f),to=inp.value,prev=f.dataset.prev||"todo";b.disabled=true;
try{await N.req(f.dataset.quickDone,{body:{status:to}});const done=to==="done";row.classList.toggle("is-done",done);b.classList.toggle("on",done);b.setAttribute("aria-pressed",done);b.setAttribute("aria-label",done?"Mark as not done":"Mark as done");f.dataset.prev=done?prev:to;inp.value=done?prev:"done";
const g=row.closest("[data-group]");if(g){const n=$$("[data-task-row]:not(.is-done)",g).length,c=$("[data-group-count]",g);if(c)c.textContent=n}N.toast(done?"Marked as done":"Reopened")}
catch(err){N.toast(err.message,"error")}finally{b.disabled=false}});
/* collapsible task groups remember their state */
$$("details[data-group]").forEach(x=>{const k="ny-tg-"+x.dataset.group;try{const v=localStorage.getItem(k);if(v==="0")x.open=false;if(v==="1")x.open=true}catch(e){}x.addEventListener("toggle",()=>{try{localStorage.setItem(k,x.open?"1":"0")}catch(e){}})});
/* filter a list of people by name */
d.addEventListener("input",e=>{const i=e.target.closest("[data-filter-list]");if(!i)return;const q=i.value.trim().toLowerCase();$$("[data-name]",$(i.dataset.filterList)).forEach(x=>x.classList.toggle("hide",q!==""&&!x.dataset.name.includes(q)))});
/* show the chosen file name in drop zones */
d.addEventListener("change",e=>{const i=e.target.closest("[data-file-label]");if(!i||!i.files.length)return;const z=i.closest(".dropzone"),t=$("[data-file-name]",z)||$(".b",z);if(t)t.textContent=i.files[0].name;z.classList.add("has-file")});
/* work report: period label + hours prefill from time entries (only when time tracking is on) */
const rf=$("[data-report-form]");
if(rf){const h=$("[data-hours]",rf),hint=$("[data-hours-hint]",rf),pl=$("[data-period-label]",rf),dl=$("[data-date-label]",rf),date=$("[name=period_start]",rf),time=rf.dataset.time==="1";let touched=rf.dataset.reportForm==="edit"&&h.value!=="";
h.addEventListener("input",()=>{touched=true});
const sync=async()=>{const type=($("[name=type]:checked",rf)||{}).value||"daily";if(dl)dl.textContent=type==="weekly"?"Week of":"Date";if(!date.value)return;
try{const j=await N.req("work-reports/hours?type="+encodeURIComponent(type)+"&start="+encodeURIComponent(date.value));if(pl)pl.textContent=j.label;if(time&&hint)hint.textContent=j.hours?j.hours+"h logged in time entries for this period.":"No time entries logged for this period.";if(time&&!touched&&j.hours)h.value=j.hours}catch(err){}};
rf.addEventListener("change",e=>{if(e.target.name==="type"||e.target===date)sync()});sync()}
})();
