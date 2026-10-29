/* Niyanta Attendance & Time Tracking: live clocks, task pickers, entry dialogs, idle detection + heartbeat, consent-based screen capture */
(()=>{"use strict";if(window.NyAttendance)return;window.NyAttendance=1;
const d=document,$=(s,c=d)=>c.querySelector(s),$$=(s,c=d)=>[...c.querySelectorAll(s)],N=()=>window.Niyanta;
const pad=n=>String(n).padStart(2,"0"),fmt=(s,f)=>{s=Math.max(0,Math.floor(+s||0));const h=Math.floor(s/3600),m=Math.floor(s%3600/60);return f==="hm"?h+"h "+pad(m)+"m":pad(h)+":"+pad(m)+":"+pad(s%60)};
const S={a:0,i:0},pct=()=>{const t=S.a+S.i,p=t?Math.round(100*S.a/t):100;S.a=0;S.i=0;return p};
/* live clocks: [data-tick][data-base] seconds at render; [data-paused] freezes; data-fmt="hm" */
const t0=Date.now(),ticks=()=>$$("[data-tick]").forEach(el=>{if(el.hasAttribute("data-paused"))return;el.textContent=fmt((+el.dataset.base||0)+(Date.now()-(el._t0||t0))/1000,el.dataset.fmt)});
const rebase=(el,base)=>{if(!el)return;el.dataset.base=base;el._t0=Date.now();ticks()};
setInterval(ticks,1000);
/* project -> task pickers: select[data-project-select][data-task-target=id] */
const loadTasks=async(sel,val)=>{const t=d.getElementById(sel.dataset.taskTarget);if(!t)return;const p=sel.value;t.disabled=!p;t.innerHTML='<option value="">'+(p?"Loading tasks...":"Select a project first")+"</option>";if(!p)return;
try{const j=await N().req("time/tasks?project_id="+encodeURIComponent(p));t.innerHTML='<option value="">No specific task</option>'+j.tasks.map(x=>'<option value="'+(+x.id)+'">'+N().esc(x.title)+(+x.mine?" ★":"")+"</option>").join("");if(val)t.value=String(val)}
catch(e){t.innerHTML='<option value="">Could not load tasks</option>'}};
d.addEventListener("change",e=>{if(e.target.matches("[data-project-select]"))loadTasks(e.target)});
/* manual time entry dialog (add / edit) */
const ef=$("[data-entry-form]");
if(ef)d.addEventListener("click",e=>{const b=e.target.closest("[data-entry-edit],[data-entry-new]");if(!b)return;const j=b.dataset.entryEdit?JSON.parse(b.dataset.entryEdit):null,F=ef.elements,set=(n,v)=>{if(F[n])F[n].value=v??""};ef.reset();
$("[data-entry-title]",ef).textContent=j?"Edit time entry":"Add time";ef.action=ef.dataset.newAction+(j?"/"+j.id:"");
if(j){set("date",j.date);set("start_time",j.start_time);set("end_time",j.end_time);set("description",j.description);F.billable.checked=!!+j.billable;set("project_id",j.project_id||"")}
loadTasks(F.project_id,j&&j.task_id)});
/* attendance record dialog (HR / manager) */
const af=$("[data-att-form]");
if(af)d.addEventListener("click",e=>{const b=e.target.closest("[data-att-edit],[data-att-new]");if(!b)return;const j=b.dataset.attEdit?JSON.parse(b.dataset.attEdit):null;af.reset();
$("[data-att-title]",af).textContent=j?"Edit · "+j.name:"Add attendance record";if(j)["user_id","work_date","clock_in","clock_out","break_minutes","status","note"].forEach(k=>{if(af.elements[k])af.elements[k].value=j[k]??""})});
/* shared reject dialog: [data-reject-url][data-reject-name] buttons fill the form before core opens the modal */
const rf=$("[data-reject-form]");
if(rf)d.addEventListener("click",e=>{const b=e.target.closest("[data-reject-url]");if(!b)return;rf.action=b.dataset.rejectUrl;rf.reset();const n=$("[data-reject-name]",rf);if(n)n.textContent=b.dataset.rejectName||"";setTimeout(()=>{const t=rf.elements.note;if(t)t.focus()},60)},true);
/* running timer: idle detection + heartbeat every 60s */
const T=$("[data-tracker]");
if(T&&T.dataset.running==="1"){const idleMs=Math.max(1,+T.dataset.idleMin||5)*60000,live=$("[data-state-live]",T),chip=$("[data-state-idle]",T),timer=$("[data-timer]",T),dlg=$("#idle-dialog");
let last=Date.now(),tick=Date.now(),idle=false,acc={a:0,i:0},busy=false;
const setIdle=on=>{idle=on;if(live)live.classList.toggle("hide",on);if(chip)chip.classList.toggle("hide",!on)};
const beat=async()=>{if(busy)return;busy=true;const a=Math.round(acc.a),i=Math.round(acc.i);acc={a:0,i:0};
try{const j=await N().req("time/heartbeat",{body:{active:a,idle:i}});if(!j.running){N().toast("Your timer was stopped elsewhere.","error");setTimeout(()=>location.reload(),1500);return}
const it=$("[data-idle-total]",T);if(it)it.textContent="Idle recorded: "+fmt(j.idle,"hm");rebase(timer,j.elapsed)}
catch(e){acc.a+=a;acc.i+=i}finally{busy=false}};
const returned=async secs=>{await beat();if(!dlg)return;$("[data-idle-mins]",dlg).textContent=(secs<3600?Math.max(1,Math.round(secs/60))+" min":fmt(secs,"hm"));dlg.dataset.secs=String(Math.round(secs));if(!dlg.open)dlg.showModal()};
const activity=()=>{const now=Date.now();if(idle){const secs=(now-last)/1000;setIdle(false);last=now;returned(secs)}else last=now};
setInterval(()=>{const now=Date.now(),dt=(now-tick)/1000;tick=now;
if(!idle&&now-last>=idleMs){setIdle(true);const extra=Math.max(0,(now-last)/1000-dt);acc.i+=extra;acc.a=Math.max(0,acc.a-extra);S.i+=extra;S.a=Math.max(0,S.a-extra)}
if(idle){acc.i+=dt;S.i+=dt}else{acc.a+=dt;S.a+=dt}},1000);
["mousemove","mousedown","keydown","wheel","touchstart","scroll"].forEach(ev=>d.addEventListener(ev,activity,{passive:true}));
d.addEventListener("visibilitychange",()=>{if(!d.hidden)activity()});
setInterval(beat,60000);
window.addEventListener("pagehide",()=>{if(!navigator.sendBeacon)return;const f=new FormData();f.append("_token",N().csrf);f.append("active",Math.round(acc.a));f.append("idle",Math.round(acc.i));navigator.sendBeacon(N().url("time/heartbeat"),f)});
if(dlg)dlg.addEventListener("click",async e=>{const keep=e.target.closest("[data-idle-keep]"),drop=e.target.closest("[data-idle-discard]");if(!keep&&!drop)return;dlg.close();
try{const j=await N().req("time/idle",{body:{action:drop?"discard":"keep",seconds:dlg.dataset.secs||0}});if(drop){N().toast(j.discarded?"Removed "+fmt(j.discarded,"hm")+" of idle time":"No idle time to remove");if(j.elapsed!=null)rebase(timer,j.elapsed)}else N().toast("Idle time kept")}
catch(err){N().toast(err.message,"error")}})}
/* consent-based screen capture (user-initiated getDisplayMedia; JPEG ~0.6, max 1280px wide) */
const C=$("[data-capture]");
if(C){let st=null,v=null,ic=null,iv=null,n=0;const on=$("[data-cap-on]",C),off=$("[data-cap-off]",C),bs=$("[data-cap-start]",C),bx=$("[data-cap-stop]",C),info=$("[data-cap-last]",C),mins=Math.max(1,+C.dataset.interval||3);
const ui=a=>{on.classList.toggle("hide",!a);off.classList.toggle("hide",a);bs.classList.toggle("hide",a);bx.classList.toggle("hide",!a)};
const stop=()=>{if(iv)clearInterval(iv);iv=null;if(st)st.getTracks().forEach(t=>t.stop());st=null;ic=null;if(v){v.srcObject=null;v=null}ui(false)};
const frame=async()=>{if(ic){try{return await ic.grabFrame()}catch(e){}}return v&&v.videoWidth?v:null};
const send=b=>{if(!b)return;const f=new FormData();f.append("shot",b,"screen.jpg");f.append("activity_pct",String(pct()));
N().req("time/screenshots",{body:f}).then(()=>{n++;info.textContent="Last capture "+new Date().toLocaleTimeString([],{hour:"2-digit",minute:"2-digit"})+" · "+n+" this session"}).catch(e=>{N().toast(e.message,"error");if(e.status===422||e.status===403)stop()})};
const grab=async()=>{const src=await frame();if(!src)return;const w=src.videoWidth||src.width,h=src.videoHeight||src.height;if(!w||!h)return;const s=Math.min(1,1280/w),c=d.createElement("canvas");c.width=Math.round(w*s);c.height=Math.round(h*s);c.getContext("2d").drawImage(src,0,0,c.width,c.height);if(src.close)src.close();c.toBlob(send,"image/jpeg",.6)};
bs.addEventListener("click",async()=>{const md=navigator.mediaDevices;if(!md||!md.getDisplayMedia){N().toast("Screen capture is not supported in this browser.","error");return}
try{st=await md.getDisplayMedia({video:{frameRate:1},audio:false});const tr=st.getVideoTracks()[0];tr.addEventListener("ended",()=>{stop();N().toast("Screen capture stopped")});
if(window.ImageCapture)ic=new ImageCapture(tr);v=d.createElement("video");v.muted=true;v.playsInline=true;v.srcObject=st;await v.play().catch(()=>{});ui(true);setTimeout(grab,4000);iv=setInterval(grab,mins*60000)}
catch(e){stop();N().toast("Screen capture was not started (permission denied or cancelled).","error")}});
bx.addEventListener("click",()=>{stop();N().toast("Screen capture stopped")});
window.addEventListener("pagehide",stop)}
})();
