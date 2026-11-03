/* Task form (views/partials/task_fields.php): same fields everywhere; the project picker only swaps the assignee list (project team / my people), milestones and billing default and enables Milestone + Billable; repeat options shown without CSS :has() */
(()=>{"use strict";const d=document,N=window.Niyanta,cache={};
const rep=f=>{const r=f.querySelector("[name=repeat]:checked");if(!r)return;f.dataset.repeat=r.value;const u=f.querySelector("[data-task-due]");if(u)u.disabled=r.value!=="once"};
const fill=(s,first,rows,keep)=>{if(!s)return;s.textContent="";if(first!==null)s.append(new Option(first,""));(rows||[]).forEach(([v,l])=>s.append(new Option(l,v)));const has=(rows||[]).some(([v])=>String(v)===String(keep));s.value=has?keep:(first!==null?"":(rows&&rows[0]?String(rows[0][0]):""))};
const mode=async(f,load)=>{const sel=f.querySelector("[data-task-project]"),pid=sel?sel.value:"",m=pid?"project":"todo";f.dataset.mode=m;
f.querySelectorAll("[data-when]").forEach(b=>{b.hidden=b.dataset.when!==m});f.querySelectorAll("[data-needs-project]").forEach(i=>{i.disabled=!pid;if(!pid&&i.type==="checkbox")i.checked=false});
if(f.dataset.projectAction&&pid)f.action=f.dataset.projectAction.replace("__ID__",pid);
if(!load||!N)return;const a=f.querySelector("[data-task-team]");
if(!pid){let p=[];try{p=JSON.parse(a&&a.dataset.people||"[]")}catch(e){}if(a&&a.dataset.people)fill(a,null,p,a.value);fill(f.querySelector("[data-task-ms]"),"None",[],"");return}
try{const j=cache[pid]||(cache[pid]=await N.req(f.dataset.projectData.replace("__ID__",pid)));if(sel.value!==pid)return;
const b=f.querySelector("[data-task-billable]");fill(a,"Unassigned",j.team,a&&a.value);fill(f.querySelector("[data-task-ms]"),"None",j.milestones,"");if(b)b.checked=!!j.billable}
catch(e){delete cache[pid];N.toast(e.message,"error")}};
d.addEventListener("change",e=>{const f=e.target.form;if(!f)return;if(e.target.name==="repeat"&&f.matches(".td-form"))rep(f);if(e.target.matches("[data-task-project]")&&f.matches("[data-task-form]"))mode(f,true)});
d.querySelectorAll(".td-form").forEach(rep);d.querySelectorAll("[data-task-form]").forEach(f=>mode(f,false))})();
