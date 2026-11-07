/* Niyanta Attendance & Time Tracking: live clocks, read-only live status (polled), OS detection for downloads, task pickers, entry/attendance/reject dialogs. Tracking itself happens in the desktop app. */
(()=>{"use strict";if(window.NyAttendance)return;window.NyAttendance=1;
const d=document,$=(s,c=d)=>c.querySelector(s),$$=(s,c=d)=>[...c.querySelectorAll(s)],N=()=>window.Niyanta;
const pad=n=>String(n).padStart(2,"0"),fmt=(s,f)=>{s=Math.max(0,Math.floor(+s||0));const h=Math.floor(s/3600),m=Math.floor(s%3600/60);return f==="hm"?h+"h "+pad(m)+"m":pad(h)+":"+pad(m)+":"+pad(s%60)};
/* live clocks: [data-tick][data-base] seconds at render; [data-paused] freezes; data-fmt="hm" */
const t0=Date.now(),ticks=()=>$$("[data-tick]").forEach(el=>{if(el.hasAttribute("data-paused"))return;el.textContent=fmt((+el.dataset.base||0)+(Date.now()-(el._t0||t0))/1000,el.dataset.fmt)});
const rebase=(el,base,run)=>{if(!el)return;el.dataset.base=base;el._t0=Date.now();el.toggleAttribute("data-paused",!run);el.textContent=fmt(base,el.dataset.fmt)};
setInterval(ticks,1000);
/* read-only live status: [data-live-status] cards refresh from time/status every 30 s */
const LS=$$("[data-live-status]");
if(LS.length){const dots={tracking:"dot-in pulse",paused:"dot-break",stopped:"dot-break",ended:"dot-done",not_started:"dot-out"},esc=s=>N().esc(String(s??""));
const hm=s=>{s=Math.max(0,+s||0);const h=Math.floor(s/3600),m=Math.floor(s%3600/60);return h?h+"h "+pad(m)+"m":m+"m"};
const tm=iso=>iso?new Date(iso).toLocaleTimeString([],{hour:"2-digit",minute:"2-digit"}):"";
const line=(j,side)=>{const r=j.running;if(r)return(side?(r.device?"On <b>"+esc(r.device)+"</b> · ":""):"Tracking"+(r.device?" on <b>"+esc(r.device)+"</b>":"")+" · ")+"<b>"+esc(r.project||(j.projects===false?"Tracked time":"No project"))+"</b>"+(r.task&&!side?" · "+esc(r.task):"");
return{paused:side?"Paused in the desktop app":"Paused since <b>"+tm(j.day.paused_since)+"</b>. Resume in the desktop app.",stopped:side?"Day open, not tracking":"Your day is open but nothing is being tracked. Is the desktop app running?",ended:side?"Day ended":"Day ended at <b>"+tm(j.day.clock_out)+"</b>. Nice work.",not_started:side?"Start your day in Niyanta Tracker":"Not started yet. Open Niyanta Tracker and click <b>Start</b>."}[j.state]||""};
const paint=j=>LS.forEach(c=>{const run=j.state==="tracking",side=c.classList.contains("side-timer");c.dataset.state=j.state;c.classList.toggle("running",run);
const ch=$("[data-ls-chip]",c);if(ch){ch.classList.toggle("live",run);ch.classList.toggle("idle",!side&&(j.state==="paused"||j.state==="stopped"));const dt=$(".dotc",ch);if(dt)dt.className="dotc "+dots[j.state]}
const lb=$("[data-ls-label]",c);if(lb)lb.textContent=j.label;rebase($("[data-ls-total]",c),j.totals.tracked,run);
const ln=$("[data-ls-line]",c);if(ln)ln.innerHTML=line(j,side);const en=$("[data-ls-entry]",c);if(en){en.classList.toggle("hide",!run);rebase($("[data-ls-entry-t]",c),run?j.running.elapsed:0,run)}
const up=$("[data-ls-updated]",c);if(up)up.textContent="Updated "+new Date().toLocaleTimeString([],{hour:"2-digit",minute:"2-digit"})});
const tiles=j=>{[["active","active"],["idle","idle"],["paused","paused"],["tracked","tracked"]].forEach(([k,a])=>{const el=$("[data-ls-"+a+"]");if(el)el.textContent=hm(j.totals[k])})};
let busy=false;const poll=async()=>{if(busy||d.hidden)return;busy=true;try{const j=await N().req("time/status");paint(j);tiles(j)}catch(e){}finally{busy=false}};
setInterval(poll,30000);d.addEventListener("visibilitychange",()=>{if(!d.hidden)poll()})}
/* desktop downloads: highlight the visitor's OS */
const G=$("[data-os-grid]");
if(G){const ua=(navigator.userAgentData&&navigator.userAgentData.platform||navigator.platform||"")+" "+navigator.userAgent,os=/win/i.test(ua)?"windows":/mac|iphone|ipad/i.test(ua)?"mac":/linux|x11|cros/i.test(ua)?(/ubuntu|debian|mint/i.test(ua)?"deb":"linux"):"";
if(os){const c=$('[data-os="'+os+'"]',G);if(c){c.classList.add("is-you");const y=$(".os-you",c);if(y)y.classList.remove("hide");G.prepend(c)}const st=$('[data-os-step="'+(os==="deb"?"linux":os)+'"]');if(st)st.classList.add("is-you")}
/* one-line install commands: the visitor's OS first, the others behind "Commands for other systems" */
const OL=$("[data-ol-list]"),oc=OL&&$('[data-ol="'+(os==="deb"?"linux":os)+'"]',OL);if(oc){oc.classList.add("is-you");const y=$(".os-you",oc);if(y)y.classList.remove("hide");OL.prepend(oc);OL.classList.add("is-short");const mb=$("[data-ol-more]");if(mb){mb.classList.remove("hide");mb.addEventListener("click",()=>{OL.classList.remove("is-short");mb.remove()})}}}
/* project -> task pickers: select[data-project-select][data-task-target=id] */
const loadTasks=async(sel,val)=>{if(!sel)return;const t=d.getElementById(sel.dataset.taskTarget);if(!t)return;const p=sel.value;t.disabled=!p;t.innerHTML='<option value="">'+(p?"Loading tasks...":"Select a project first")+"</option>";if(!p)return;
try{const j=await N().req("time/tasks?project_id="+encodeURIComponent(p));t.innerHTML='<option value="">No specific task</option>'+j.tasks.map(x=>'<option value="'+(+x.id)+'">'+N().esc(x.title)+(+x.mine?" ★":"")+"</option>").join("");if(val)t.value=String(val)}
catch(e){t.innerHTML='<option value="">Could not load tasks</option>'}};
d.addEventListener("change",e=>{if(e.target.matches("[data-project-select]"))loadTasks(e.target)});
/* manual time entry dialog (add / edit) */
const ef=$("[data-entry-form]");
if(ef)d.addEventListener("click",e=>{const b=e.target.closest("[data-entry-edit],[data-entry-new]");if(!b)return;const j=b.dataset.entryEdit?JSON.parse(b.dataset.entryEdit):null,F=ef.elements,set=(n,v)=>{if(F[n])F[n].value=v??""};ef.reset();
$("[data-entry-title]",ef).textContent=j?"Edit time entry":"Add time manually";ef.action=ef.dataset.newAction+(j?"/"+j.id:"");
if(j){set("date",j.date);set("start_time",j.start_time);set("end_time",j.end_time);set("description",j.description);if(F.billable)F.billable.checked=!!+j.billable;set("project_id",j.project_id||"")}
loadTasks(F.project_id,j&&j.task_id)});
/* attendance record dialog (HR / manager) */
const af=$("[data-att-form]");
if(af)d.addEventListener("click",e=>{const b=e.target.closest("[data-att-edit],[data-att-new]");if(!b)return;const j=b.dataset.attEdit?JSON.parse(b.dataset.attEdit):null;af.reset();
$("[data-att-title]",af).textContent=j?"Edit · "+j.name:"Add attendance record";if(j)["user_id","work_date","clock_in","clock_out","break_minutes","status","note"].forEach(k=>{if(af.elements[k])af.elements[k].value=j[k]??""})});
/* shared reject dialog: [data-reject-url][data-reject-name] buttons fill the form before core opens the modal */
const rf=$("[data-reject-form]");
if(rf)d.addEventListener("click",e=>{const b=e.target.closest("[data-reject-url]");if(!b)return;rf.action=b.dataset.rejectUrl;rf.reset();const n=$("[data-reject-name]",rf);if(n)n.textContent=b.dataset.rejectName||"";setTimeout(()=>{const t=rf.elements.note;if(t)t.focus()},60)},true);
/* screenshot gallery: any [data-shots] element (JSON list of {src,t,a,d,g}: slot cards, chart bars) opens #shot-viewer in the page with prev/next, thumbnails, arrow keys and Esc; never navigates away */
const SV=d.getElementById("shot-viewer");
if(SV){let L=[],i=0;const img=$("[data-sv-img]",SV),lk=$("[data-sv-link]",SV),dl=$("[data-sv-dl]",SV),mt=$("[data-sv-meta]",SV),th=$("[data-sv-thumbs]",SV),cn=$("[data-sv-count]",SV),E=s=>N().esc(String(s??""));
const lvl=a=>a>=60?"hi":a>=30?"mid":"lo";
const show=n=>{if(!L.length)return;i=(n+L.length)%L.length;const s=L[i];img.src=s.src;img.alt="Screenshot at "+s.t+(s.d?", screen "+s.d:"");lk.href=s.src;dl.href=s.src;
mt.innerHTML="<b>"+E(s.t)+"</b>"+(s.d?"<span>Screen "+(+s.d)+"</span>":"")+(s.a!=null?'<span class="sv-a '+lvl(+s.a)+'"><i></i>'+(+s.a)+"% activity</span>":"<span>No activity data</span>")+(s.g?'<span class="pill pill-sky">Meeting</span>':"");
cn.textContent="Screenshot "+(i+1)+" of "+L.length;$$("button",th).forEach((b,k)=>{const on=k===i;b.classList.toggle("on",on);b.setAttribute("aria-current",on?"true":"false");if(on)th.scrollLeft=b.offsetLeft-th.clientWidth/2+b.clientWidth/2})};
d.addEventListener("click",e=>{const c=e.target.closest("[data-shots]");if(c){e.preventDefault();try{L=JSON.parse(c.dataset.shots)}catch(x){L=[]}if(!L.length)return;$("[data-sv-title]",SV).textContent=c.dataset.shotsTitle||"Screenshots";
th.innerHTML=L.length>1?L.map((s,k)=>'<button type="button" data-sv-go="'+k+'" aria-label="Screenshot '+E(s.t)+(s.d?", screen "+(+s.d):"")+'"><img src="'+E(s.src)+'" alt="" loading="lazy"></button>').join(""):"";th.hidden=L.length<2;$$(".sv-prev,.sv-next",SV).forEach(b=>b.hidden=L.length<2);if(!SV.open)SV.showModal();show(0);return}
if(!SV.contains(e.target))return;const g=e.target.closest("[data-sv-go]");if(g){show(+g.dataset.svGo);return}const st=e.target.closest("[data-sv-step]");if(st){show(i+ +st.dataset.svStep);return}if(e.target===img&&L.length>1)show(i+1)});
SV.addEventListener("keydown",e=>{if(e.key==="ArrowLeft"){e.preventDefault();show(i-1)}else if(e.key==="ArrowRight"){e.preventDefault();show(i+1)}});
SV.addEventListener("close",()=>img.removeAttribute("src"))}
/* activity chart: tooltip on hover; a bar with a slot card ([data-ac-slot]) scrolls to it, a bar with [data-shots] opens the gallery (above) */
const AC=$$("[data-ac]");
if(AC.length){const tp=d.createElement("div"),hide=()=>{tp.hidden=true};tp.className="ac-tip";tp.hidden=true;tp.setAttribute("aria-hidden","true");d.body.appendChild(tp);
AC.forEach(p=>{p.addEventListener("mouseover",e=>{const b=e.target.closest(".ac-bar");if(!b)return;tp.textContent=b.dataset.tip||"";tp.hidden=false;const r=b.getBoundingClientRect(),w=tp.offsetWidth,h=tp.offsetHeight;tp.style.left=Math.max(8,Math.min(innerWidth-w-8,r.left+r.width/2-w/2))+"px";tp.style.top=(r.top-h-10<8?r.bottom+10:r.top-h-10)+"px"});p.addEventListener("mouseleave",hide);
p.addEventListener("click",e=>{const b=e.target.closest("[data-ac-slot]"),c=b&&d.getElementById("slot-"+b.dataset.acSlot);if(!c)return;hide();c.scrollIntoView({behavior:matchMedia("(prefers-reduced-motion: reduce)").matches?"auto":"smooth",block:"center"});c.classList.remove("flash");void c.offsetWidth;c.classList.add("flash");setTimeout(()=>c.focus({preventScroll:true}),450)})});
addEventListener("scroll",hide,{passive:true})}
})();
