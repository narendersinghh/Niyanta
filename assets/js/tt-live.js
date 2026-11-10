/* Top bar (Attendance): the own live tracking chip ticks every second and checks every 2 minutes whether tracking still runs; the team "N active" pill (attendance.view_all) refreshes its count every 2 minutes while the tab is visible and loads its popup from time/team/now when opened. */
(()=>{"use strict";const d=document,N=()=>window.Niyanta,p=n=>String(n).padStart(2,"0");
const c=d.querySelector("[data-tt-live]");
if(c){const k=c.querySelector("[data-tt-clock]");let b=+c.dataset.base||0,t=Date.now();
const f=s=>{s=Math.max(0,Math.floor(s));return Math.floor(s/3600)+":"+p(Math.floor(s%3600/60))+":"+p(s%60)};setInterval(()=>{k.textContent=f(b+(Date.now()-t)/1e3)},1000);
setInterval(async()=>{if(d.hidden||!N())return;try{const j=await N().req("time/status");if(j.state!=="tracking"){c.remove();return}b=j.running.elapsed;t=Date.now();const w=c.querySelector(".tt-what");if(w&&j.projects)w.textContent=j.running.project||"No project"}catch(e){}},120000)}
const T=d.querySelector("[data-team-now]");
if(T){const n=T.querySelector("[data-tm-count]"),P=T.querySelector("[data-tm-pop]"),dot=T.querySelector(".tm-dot"),sm=T.querySelector("summary"),E=s=>N().esc(String(s??""));let last=Date.now(),busy=false;
const set=v=>{v=+v||0;n.textContent=v;dot.classList.toggle("on",v>0);sm.setAttribute("aria-label",v+" team member"+(v===1?"":"s")+" active now. Show who is working")};
const count=async()=>{if(busy||d.hidden||!N())return;busy=true;try{const j=await N().req("time/team/now?count=1");set(j.active);last=Date.now()}catch(e){}finally{busy=false}};
setInterval(count,120000);d.addEventListener("visibilitychange",()=>{if(!d.hidden&&Date.now()-last>=120000)count()});
const CAP={tracking:["mint","Tracking"],paused:["lemon","Paused"],idle:["peach","Idle"],stopped:["sky","Clocked in"],ended:["lav","Day ended"],leave:["pink","On leave"],not_started:["gray","Not in yet"]};
const cap=r=>{const c=CAP[r.status]||["gray",r.label];return'<span class="tm-cap tm-c-'+c[0]+'">'+E(r.label||c[1])+"</span>"};
const row=(r,full)=>{const inner=r.avatar+'<span class="tm-who"><b>'+E(r.name)+"</b>"+(full&&r.project?'<span class="tm-sub">'+E(r.project)+(r.task?" · "+E(r.task):"")+"</span>":full&&r.since?'<span class="tm-sub">'+E(r.since)+"</span>":"")+'</span><span class="tm-when">'+cap(r)+(full&&r.dur?"<b>"+E(r.dur)+"</b>":"")+"</span>";
return full&&r.url?'<a class="tm-row" href="'+E(r.url)+'" title="Daily timesheet of '+E(r.name)+'">'+inner+"</a>":'<div class="tm-row">'+inner+"</div>"};
const GR=[["active","Active","mint","Nobody is tracking right now."],["away","Inactive","lemon","Nobody else logged in today."],["leave","On leave","pink",""],["notin","Not in yet","gray",""]];
const more=(j,k)=>j.counts[k]>j[k].length?(j.full&&j.team_url?'<a class="tm-more" href="'+E(j.team_url)+'">+'+(j.counts[k]-j[k].length)+" more on the Team tab</a>":'<p class="tm-more">+'+(j.counts[k]-j[k].length)+" more</p>"):"";
const html=j=>'<div class="tm-head"><b>Team right now</b><div class="tm-caps" role="group" aria-label="Filter">'+GR.map(([k,l,c])=>'<button type="button" class="tm-cap tm-c-'+c+'" data-tm-f="'+k+'" aria-pressed="false">'+l+" <b>"+(j.counts[k]||0)+"</b></button>").join("")+'</div></div><div class="tm-body">'
+GR.map(([k,l,c,empty])=>(j[k].length||empty)?'<section class="tm-sec" data-tm-sec="'+k+'"><h4><i class="tm-dot tm-c-'+c+'" aria-hidden="true"></i>'+l+(k==="away"?' <span class="tm-h-note">logged in today</span>':"")+"</h4>"+(j[k].length?j[k].map(r=>row(r,j.full)).join("")+more(j,k):'<p class="tm-empty">'+empty+"</p>")+"</section>":"").join("")+"</div>"
+'<div class="tm-foot">Updated '+E(j.updated)+(j.full&&j.team_url?' · <a href="'+E(j.team_url)+'">Open team attendance</a>':"")+"</div>";
P.addEventListener("click",e=>{const b=e.target.closest("[data-tm-f]");if(!b)return;const on=b.getAttribute("aria-pressed")!=="true";P.querySelectorAll("[data-tm-f]").forEach(x=>x.setAttribute("aria-pressed",x===b&&on?"true":"false"));P.querySelectorAll("[data-tm-sec]").forEach(sec=>sec.hidden=on&&sec.dataset.tmSec!==b.dataset.tmF)});
T.addEventListener("toggle",async()=>{if(!T.open||!N())return;if(!P.dataset.loaded)P.innerHTML='<p class="tm-empty">Loading...</p>';try{const j=await N().req("time/team/now");set(j.counts.active);last=Date.now();P.innerHTML=html(j);P.dataset.loaded=1}catch(e){P.innerHTML='<p class="tm-empty">Could not load your team. Please try again.</p>'}})}
})();
