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
const row=(r,on)=>'<a class="tm-row" href="'+E(r.url)+'" title="Daily timesheet of '+E(r.name)+'">'+r.avatar+'<span class="tm-who"><b>'+E(r.name)+'</b><span class="tm-sub">'+(on?E(r.project)+(r.task?" · "+E(r.task):""):'<i class="tm-st tm-'+E(r.status)+'" aria-hidden="true"></i>'+E(r.label))+'</span></span><span class="tm-when">'+(r.dur?"<b>"+E(r.dur)+"</b>":"")+(r.since?"<span>"+E(r.since)+"</span>":"")+"</span></a>";
const more=(j,k)=>j.counts[k]>j[k].length?'<a class="tm-more" href="'+E(j.team_url)+'">+'+(j.counts[k]-j[k].length)+" more on the Team tab</a>":"";
const html=j=>'<div class="tm-head"><b>Team right now</b><a class="tm-link" href="'+E(j.team_url)+'">Team tab</a></div><div class="tm-body">'
+'<section class="tm-sec"><h4><i class="tm-dot on" aria-hidden="true"></i>Active now<span class="tm-n">'+j.counts.active+"</span></h4>"+(j.active.length?j.active.map(r=>row(r,1)).join("")+more(j,"active"):'<p class="tm-empty">Nobody is tracking right now.</p>')+"</section>"
+'<section class="tm-sec"><h4><i class="tm-dot" aria-hidden="true"></i>Not active<span class="tm-n">'+(j.counts.away+j.counts.off)+"</span></h4>"+(j.away.length?'<div class="tm-sub-h">Paused or idle</div>'+j.away.map(r=>row(r,0)).join("")+more(j,"away"):"")+(j.off.length?'<div class="tm-sub-h">Not started, on leave or done</div>'+j.off.map(r=>row(r,0)).join("")+more(j,"off"):"")+(j.away.length||j.off.length?"":'<p class="tm-empty">Everyone is tracking.</p>')+"</section></div>"
+'<div class="tm-foot">Updated '+E(j.updated)+' · <a href="'+E(j.team_url)+'">Open team attendance</a></div>';
T.addEventListener("toggle",async()=>{if(!T.open||!N())return;if(!P.dataset.loaded)P.innerHTML='<p class="tm-empty">Loading...</p>';try{const j=await N().req("time/team/now");set(j.counts.active);last=Date.now();P.innerHTML=html(j);P.dataset.loaded=1}catch(e){P.innerHTML='<p class="tm-empty">Could not load your team. Please try again.</p>'}})}
})();
