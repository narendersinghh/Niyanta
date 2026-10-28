/* Projects module: Kanban drag & drop, task modal presets, member filter, file labels, work report hours prefill */
(()=>{"use strict";const d=document,$=(s,c=d)=>c.querySelector(s),$$=(s,c=d)=>[...c.querySelectorAll(s)],N=window.Niyanta;
/* Kanban: HTML5 DnD; server validates permission + dependencies and we revert on error */
const board=$("[data-board]");
if(board){let drag=null,origin=null,next=null;
const counts=()=>$$(".kcol",board).forEach(c=>{const n=$$(".kcard",c).length,el=$("[data-count]",c),e=$(".kempty",c);if(el)el.textContent=n;if(e)e.classList.toggle("hide",n>0)});
const after=(col,y)=>$$(".kcard:not(.dragging)",col).find(c=>{const r=c.getBoundingClientRect();return y<r.top+r.height/2})||null;
board.addEventListener("dragstart",e=>{const c=e.target.closest(".kcard[draggable=true]");if(!c){e.preventDefault();return}drag=c;origin=c.parentElement;next=c.nextElementSibling;c.classList.add("dragging");e.dataTransfer.effectAllowed="move";e.dataTransfer.setData("text/plain",c.dataset.id)});
board.addEventListener("dragend",()=>{if(drag)drag.classList.remove("dragging");$$(".kcol.over",board).forEach(c=>c.classList.remove("over"))});
board.addEventListener("dragover",e=>{const col=e.target.closest(".kcol");if(!col||!drag)return;e.preventDefault();e.dataTransfer.dropEffect="move";$$(".kcol.over",board).forEach(c=>c!==col&&c.classList.remove("over"));col.classList.add("over");const drop=$(".kdrop",col),ref=after(drop,e.clientY);if(ref)drop.insertBefore(drag,ref);else drop.insertBefore(drag,$(".kempty",drop))});
board.addEventListener("drop",async e=>{const col=e.target.closest(".kcol");if(!col||!drag)return;e.preventDefault();col.classList.remove("over");const card=drag,from=origin,sib=next;drag=null;counts();
const status=col.dataset.status,order=$$(".kcard",col).map(c=>c.dataset.id).join(",");
if(from===card.parentElement&&sib===card.nextElementSibling)return;
card.classList.add("saving");
try{const j=await N.req("tasks/"+card.dataset.id+"/move",{body:{status,order}});card.classList.toggle("is-late",card.classList.contains("is-late")&&status!=="done");N.toast(j.message||"Saved")}
catch(err){from.insertBefore(card,sib&&sib.parentElement===from?sib:$(".kempty",from));counts();N.toast(err.message,"error")}
finally{card.classList.remove("saving")}});
counts()}
/* "Add task" buttons preset the status of the new-task modal */
d.addEventListener("click",e=>{const b=e.target.closest('[data-modal="task-new"][data-status]');if(!b)return;const s=$("#task-new [data-task-status]");if(s)s.value=b.dataset.status});
/* filter a list of people by name */
d.addEventListener("input",e=>{const i=e.target.closest("[data-filter-list]");if(!i)return;const q=i.value.trim().toLowerCase();$$("[data-name]",$(i.dataset.filterList)).forEach(x=>x.classList.toggle("hide",q!==""&&!x.dataset.name.includes(q)))});
/* show chosen file name in drop zones */
d.addEventListener("change",e=>{const i=e.target.closest("[data-file-label]");if(!i||!i.files.length)return;const z=i.closest(".dropzone"),t=$("[data-file-name]",z)||$(".b",z);if(t)t.textContent=i.files[0].name;z.classList.add("has-file")});
/* work report: type toggle + hours prefill from time entries */
const rf=$("[data-report-form]");
if(rf){const h=$("[data-hours]",rf),hint=$("[data-hours-hint]",rf),pl=$("[data-period-label]",rf),dl=$("[data-date-label]",rf),date=$("[name=period_start]",rf);let touched=rf.dataset.reportForm==="edit"&&h.value!=="";
h.addEventListener("input",()=>{touched=true});
const sync=async()=>{const type=($("[name=type]:checked",rf)||{}).value||"daily";$$(".type-seg .seg-btn",rf).forEach(l=>l.classList.toggle("on",$("input",l).checked));if(dl)dl.textContent=type==="weekly"?"Week of":"Date";if(!date.value)return;
try{const j=await N.req("work-reports/hours?type="+encodeURIComponent(type)+"&start="+encodeURIComponent(date.value));if(pl)pl.textContent=j.label;if(hint)hint.textContent=j.hours?j.hours+"h logged in time entries for this period.":"No time entries logged for this period.";if(!touched&&j.hours)h.value=j.hours}catch(err){}};
rf.addEventListener("change",e=>{if(e.target.name==="type"||e.target===date)sync()});sync()}
})();
