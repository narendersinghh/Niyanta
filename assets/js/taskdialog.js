/* New task dialog (views/partials/task_dialog.php): "No project" = to-do / routine, a project = project task (team, milestones, form action); repeat options shown without CSS :has() */
(()=>{"use strict";const d=document,N=window.Niyanta,cache={};
const rep=f=>{const r=f.querySelector("[name=repeat]:checked");if(r)f.dataset.repeat=r.value};
const fill=(s,first,rows,keep)=>{if(!s)return;s.textContent="";s.append(new Option(first,""));(rows||[]).forEach(([v,l])=>s.append(new Option(l,v)));s.value=(rows||[]).some(([v])=>String(v)===String(keep))?keep:""};
const mode=async(f,load)=>{const sel=f.querySelector("[data-task-project]"),pid=sel?sel.value:"",m=pid?"project":"todo";f.dataset.mode=m;
f.querySelectorAll("[data-when]").forEach(b=>{const on=b.dataset.when===m;b.hidden=!on;if(b.tagName==="FIELDSET")b.disabled=!on});
if(pid)f.action=f.dataset.projectAction.replace("__ID__",pid);else if(f.dataset.todoAction)f.action=f.dataset.todoAction;
const t=f.querySelector("[name=title]");if(t)t.maxLength=pid?255:190;
if(!pid||!load||!N)return;
try{const j=cache[pid]||(cache[pid]=await N.req(f.dataset.projectData.replace("__ID__",pid)));if(sel.value!==pid)return;
const a=f.querySelector("[data-task-team]"),b=f.querySelector("[data-task-billable]");fill(a,"Unassigned",j.team,a&&a.value);fill(f.querySelector("[data-task-ms]"),"None",j.milestones,"");if(b)b.checked=!!j.billable}
catch(e){delete cache[pid];N.toast(e.message,"error")}};
d.addEventListener("change",e=>{const f=e.target.form;if(!f)return;if(e.target.name==="repeat"&&f.matches(".td-form"))rep(f);if(e.target.matches("[data-task-project]")&&f.matches("[data-task-form]"))mode(f,true)});
d.querySelectorAll(".td-form").forEach(rep);d.querySelectorAll("[data-task-form]").forEach(f=>mode(f,false))})();
