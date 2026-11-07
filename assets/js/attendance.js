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
const tm=iso=>iso?new Date(iso).toLocaleTimeString([],{hour:"numeric",minute:"2-digit",hour12:document.documentElement.dataset.clock!=="24"}):"";
const line=(j,side)=>{const r=j.running;if(r)return(side?(r.device?"On <b>"+esc(r.device)+"</b> · ":""):"Tracking"+(r.device?" on <b>"+esc(r.device)+"</b>":"")+" · ")+"<b>"+esc(r.project||(j.projects===false?"Tracked time":"No project"))+"</b>"+(r.task&&!side?" · "+esc(r.task):"");
return{paused:side?"Paused in the desktop app":"Paused since <b>"+tm(j.day.paused_since)+"</b>. Resume in the desktop app.",stopped:side?"Day open, not tracking":"Your day is open but nothing is being tracked. Is the desktop app running?",ended:side?"Day ended":"Day ended at <b>"+tm(j.day.clock_out)+"</b>. Nice work.",not_started:side?"Start your day in Niyanta Tracker":"Not started yet. Open Niyanta Tracker and click <b>Start</b>."}[j.state]||""};
const paint=j=>LS.forEach(c=>{const run=j.state==="tracking",side=c.classList.contains("side-timer");c.dataset.state=j.state;c.classList.toggle("running",run);
const ch=$("[data-ls-chip]",c);if(ch){ch.classList.toggle("live",run);ch.classList.toggle("idle",!side&&(j.state==="paused"||j.state==="stopped"));const dt=$(".dotc",ch);if(dt)dt.className="dotc "+dots[j.state]}
const lb=$("[data-ls-label]",c);if(lb)lb.textContent=j.label;rebase($("[data-ls-total]",c),j.totals.tracked,run);
const ln=$("[data-ls-line]",c);if(ln)ln.innerHTML=line(j,side);const en=$("[data-ls-entry]",c);if(en){en.classList.toggle("hide",!run);rebase($("[data-ls-entry-t]",c),run?j.running.elapsed:0,run)}
const up=$("[data-ls-updated]",c);if(up)up.textContent="Updated "+new Date().toLocaleTimeString([],{hour:"numeric",minute:"2-digit",hour12:document.documentElement.dataset.clock!=="24"})});
const tiles=j=>{[["active","active"],["idle","idle"],["paused","paused"],["tracked","tracked"]].forEach(([k,a])=>{const el=$("[data-ls-"+a+"]");if(el)el.textContent=hm(j.totals[k])});
const pg=$("[data-ls-prog]"),pc=$("[data-ls-pct]");if(pg&&+pg.dataset.target){const v=Math.min(100,Math.round(100*j.totals.tracked/pg.dataset.target));pg.style.width=v+"%";pg.parentNode.setAttribute("aria-valuenow",v);if(pc)pc.textContent=v+"%"}};
let busy=false;const poll=async()=>{if(busy||d.hidden)return;busy=true;try{const j=await N().req("time/status");paint(j);tiles(j)}catch(e){}finally{busy=false}};
setInterval(poll,30000);d.addEventListener("visibilitychange",()=>{if(!d.hidden)poll()})}
/* folded sections ([data-hash-open] details) open when the address points at them or into them, e.g. /time#desktop or #connect */
const HO=()=>{const h=location.hash.slice(1),t=h&&d.getElementById(h),x=t&&(t.matches("details[data-hash-open]")?t:t.closest("details[data-hash-open]"));if(x){x.open=true;setTimeout(()=>t.scrollIntoView({block:"start"}),0)}};HO();addEventListener("hashchange",HO);
/* hub tabs scroll sideways on small screens: bring the current tab into view */
const HT=$(".hub-tabs [aria-current=page]");if(HT){const t=HT.parentNode,r=HT.getBoundingClientRect(),q=t.getBoundingClientRect();if(r.right>q.right||r.left<q.left)t.scrollLeft+=r.left-q.left-(q.width-r.width)/2}
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
/* whole-day screenshot gallery: #shot-viewer[data-day] holds every capture of the day ({id,src,t,a,d,g,h,r,p}, HubController::gallery); any [data-shot-at=n] (slot cards, chart bars) opens it at item n.
   Full-screen lightbox: prev/next, arrow keys, Home/End, Esc, swipe; clicking the image toggles fit / actual size; hour-grouped filmstrip with lazy thumbnails; neighbours preloaded; focus kept inside; page scroll locked */
const SV=d.getElementById("shot-viewer");
if(SV){let L=[],i=0,back=null,zoom=false,sx=null,sy=0,swiped=0;try{L=JSON.parse(SV.dataset.day||"[]")}catch(x){}
const img=$("[data-sv-img]",SV),fig=img.parentNode,stage=$("[data-sv-stage]",SV),cap=$("[data-sv-cap]",SV),strip=$("[data-sv-strip]",SV),cnt=$("[data-sv-count]",SV),lk=$("[data-sv-link]",SV),dl=$("[data-sv-dl]",SV),zb=$("[data-sv-zoom]",SV),zl=$("[data-sv-zl]",SV),steps=$$("[data-sv-step]",SV);
const E=s=>N().esc(String(s??"")),lvl=a=>a>=60?"hi":a>=30?"mid":"lo",pre={},rm=()=>matchMedia("(prefers-reduced-motion: reduce)").matches;
const load=k=>{const s=L[k];if(s&&!pre[k]){pre[k]=new Image();pre[k].src=s.src}};
const setZoom=z=>{zoom=z;SV.classList.toggle("zoomed",z);zb.setAttribute("aria-pressed",z?"true":"false");zl.textContent=z?"Fit to screen":"Actual size";zb.title=z?"Fit the screenshot to the screen":"Show at actual size";fig.scrollTo(0,0)};
const build=()=>{let h=null,html="";L.forEach((s,k)=>{if(s.h!==h){html+=(h===null?"":"</div></div>")+'<div class="sv-grp" role="group" aria-label="'+E(s.h)+'"><span class="sv-hl" aria-hidden="true">'+E(s.h)+'</span><div class="sv-row">';h=s.h}
html+='<button type="button" tabindex="-1" data-sv-go="'+k+'" aria-label="'+E(s.t+(s.d?", screen "+s.d:""))+'"><img data-src="'+E(s.src)+'" alt=""></button>'});strip.innerHTML=html+"</div></div>";
const th=$$("img[data-src]",strip),go=t=>{t.src=t.dataset.src;t.removeAttribute("data-src")};
if("IntersectionObserver" in window){const io=new IntersectionObserver(es=>es.forEach(e=>{if(e.isIntersecting){go(e.target);io.unobserve(e.target)}}),{root:strip,rootMargin:"0px 480px"});th.forEach(t=>io.observe(t))}else th.forEach(go)};
const show=n=>{if(!L.length)return;i=Math.max(0,Math.min(L.length-1,n));const s=L[i];setZoom(false);SV.classList.add("loading");img.onload=img.onerror=()=>SV.classList.remove("loading");img.src=s.src;if(img.complete)SV.classList.remove("loading");
img.alt="Screenshot at "+s.t+(s.d?", screen "+s.d:"")+(s.p?", "+s.p:"");lk.href=s.src;dl.href=s.src;cnt.textContent=(i+1)+" / "+L.length;cnt.setAttribute("aria-label","Screenshot "+(i+1)+" of "+L.length);
cap.innerHTML='<b class="sv-t">'+E(s.t)+'</b><span class="sv-r">'+E(s.r)+"</span>"+(s.d?"<span>Screen "+(+s.d)+"</span>":"")+(s.a!=null?'<span class="sv-a '+lvl(+s.a)+'"><i></i>'+(+s.a)+"% activity</span>":'<span class="sv-a none"><i></i>No activity data</span>')+(s.g?'<span class="sv-meet">Meeting</span>':"")+(s.p?'<span class="sv-p" title="'+E(s.p)+'">'+E(s.p)+"</span>":"");
steps.forEach(b=>{b.disabled=+b.dataset.svStep<0?i===0:i===L.length-1});if(d.activeElement&&d.activeElement.disabled)(steps.find(b=>!b.disabled)||$("[data-sv-close]",SV)).focus();
$$("[data-sv-go]",strip).forEach(b=>{const on=+b.dataset.svGo===i;b.classList.toggle("on",on);b.tabIndex=on?0:-1;on?b.setAttribute("aria-current","true"):b.removeAttribute("aria-current")});
const b=$('[data-sv-go="'+i+'"]',strip);if(b){const r=b.getBoundingClientRect(),q=strip.getBoundingClientRect();strip.scrollTo({left:strip.scrollLeft+r.left-q.left-q.width/2+r.width/2,behavior:rm()?"auto":"smooth"})}
load(i+1);load(i-1)};
const open=n=>{if(!L.length)return;back=d.activeElement;if(!strip.childElementCount)build();d.documentElement.classList.add("sv-lock");if(!SV.open)SV.showModal();show(n);$("[data-sv-close]",SV).focus()};
SV.addEventListener("close",()=>{d.documentElement.classList.remove("sv-lock");img.removeAttribute("src");setZoom(false);if(back&&back.focus)back.focus({preventScroll:true})});
d.addEventListener("click",e=>{const c=e.target.closest("[data-shot-at]");if(c&&!SV.contains(c)){e.preventDefault();open(+c.dataset.shotAt);return}if(!SV.open||!SV.contains(e.target))return;
if(e.target.closest("[data-sv-close]")){SV.close();return}const g=e.target.closest("[data-sv-go]");if(g){show(+g.dataset.svGo);return}const st=e.target.closest("[data-sv-step]");if(st){show(i+ +st.dataset.svStep);return}
if(e.target.closest("[data-sv-zoom]")||(e.target===img&&Date.now()-swiped>400))setZoom(!zoom)});
SV.addEventListener("keydown",e=>{const k=e.key;if(k==="ArrowLeft"||k==="ArrowRight"){e.preventDefault();show(i+(k==="ArrowLeft"?-1:1))}else if(k==="Home"||k==="End"){e.preventDefault();show(k==="Home"?0:L.length-1)}
else if(k==="Tab"){const f=$$("button,a[href]",SV).filter(x=>!x.disabled&&x.tabIndex>=0&&x.getClientRects().length);if(!f.length)return;const a=f[0],z=f[f.length-1];if(e.shiftKey&&d.activeElement===a){e.preventDefault();z.focus()}else if(!e.shiftKey&&d.activeElement===z){e.preventDefault();a.focus()}}});
stage.addEventListener("touchstart",e=>{sx=zoom||e.touches.length>1?null:e.touches[0].clientX;if(sx!==null)sy=e.touches[0].clientY},{passive:true});
stage.addEventListener("touchend",e=>{if(sx===null)return;const t=e.changedTouches[0],dx=t.clientX-sx,dy=t.clientY-sy;sx=null;if(Math.abs(dx)>50&&Math.abs(dx)>Math.abs(dy)*1.5){swiped=Date.now();show(i+(dx<0?1:-1))}},{passive:true})}
/* activity chart: tooltip on hover; a bar with a slot card ([data-ac-slot]) scrolls to it, a bar with [data-shot-at] opens the day gallery (above) */
const AC=$$("[data-ac]");
if(AC.length){const tp=d.createElement("div"),hide=()=>{tp.hidden=true};tp.className="ac-tip";tp.hidden=true;tp.setAttribute("aria-hidden","true");d.body.appendChild(tp);
AC.forEach(p=>{p.addEventListener("mouseover",e=>{const b=e.target.closest(".ac-bar");if(!b)return;tp.textContent=b.dataset.tip||"";tp.hidden=false;const r=b.getBoundingClientRect(),w=tp.offsetWidth,h=tp.offsetHeight;tp.style.left=Math.max(8,Math.min(innerWidth-w-8,r.left+r.width/2-w/2))+"px";tp.style.top=(r.top-h-10<8?r.bottom+10:r.top-h-10)+"px"});p.addEventListener("mouseleave",hide);
p.addEventListener("click",e=>{const b=e.target.closest("[data-ac-slot]"),c=b&&d.getElementById("slot-"+b.dataset.acSlot);if(!c)return;hide();c.scrollIntoView({behavior:matchMedia("(prefers-reduced-motion: reduce)").matches?"auto":"smooth",block:"center"});c.classList.remove("flash");void c.offsetWidth;c.classList.add("flash");setTimeout(()=>c.focus({preventScroll:true}),450)})});
addEventListener("scroll",hide,{passive:true})}
})();
