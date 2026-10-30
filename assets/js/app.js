/* Niyanta core UI: theme, nav, dropdowns, toasts, modals, confirm, ajax helper, notifications, search shortcut, line items */
(()=>{"use strict";const d=document,$=(s,c=d)=>c.querySelector(s),$$=(s,c=d)=>[...c.querySelectorAll(s)];
const meta=n=>($(`meta[name="${n}"]`)||{}).content||"";
const N=window.Niyanta={base:meta("base-url").replace(/\/$/,""),csrf:meta("csrf-token"),url(p){return this.base+"/"+String(p).replace(/^\//,"")},
async req(p,o={}){const h={"X-Requested-With":"XMLHttpRequest","X-CSRF-Token":N.csrf,Accept:"application/json",...(o.headers||{})};let b=o.body;
if(b&&!(b instanceof FormData)&&typeof b==="object"){h["Content-Type"]="application/x-www-form-urlencoded";b=new URLSearchParams({...b,_token:N.csrf})}
else if(b instanceof FormData&&!b.has("_token")){b.append("_token",N.csrf)}
const r=await fetch(p.startsWith("http")?p:N.url(p),{method:o.method||(b?"POST":"GET"),headers:h,body:b,credentials:"same-origin"});let j={};try{j=await r.json()}catch(e){}
if(!r.ok){throw Object.assign(new Error(j.error||"Request failed ("+r.status+")"),{status:r.status,data:j})}return j},
toast(m,t="ok"){const w=$("[data-toasts]");if(!w)return;const el=d.createElement("div");el.className="toast"+(t==="error"?" error":"");el.innerHTML='<span class="t-ic">'+(t==="error"?"!":"✓")+"</span>";el.append(m);w.append(el);setTimeout(()=>el.remove(),4500)},
esc(s){return String(s??"").replace(/[&<>"']/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c]))},
fmtMoney(v){return(Number(v)||0).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})}};
/* report JavaScript errors to Audit log > Error logs (max 5 per page) */
let errN=0;const report=o=>{if(errN++>4||!navigator.sendBeacon)return;try{navigator.sendBeacon(N.url("errors/client"),new Blob([JSON.stringify({...o,page:location.pathname+location.search})],{type:"application/json"}))}catch(e){}};
addEventListener("error",e=>{if(e.message)report({message:e.message,source:(e.filename||"").replace(location.origin,""),line:e.lineno,column:e.colno,stack:e.error&&e.error.stack})});
addEventListener("unhandledrejection",e=>{const r=e.reason||{};report({message:"Unhandled promise rejection: "+(r.message||String(r)),stack:r.stack})});
/* theme (guests keep their choice in localStorage) */
const html=d.documentElement,guest=html.dataset.guest==="1",applyTheme=p=>{html.dataset.pref=p;html.dataset.theme=p==="system"?(matchMedia("(prefers-color-scheme: dark)").matches?"dark":"light"):p;$$("[data-theme-set]").forEach(b=>b.classList.toggle("on",b.dataset.themeSet===p))};
applyTheme(html.dataset.pref||"system");
matchMedia("(prefers-color-scheme: dark)").addEventListener("change",()=>{if(html.dataset.pref==="system")applyTheme("system")});
d.addEventListener("click",e=>{const t=e.target.closest("[data-theme-set]");if(!t)return;applyTheme(t.dataset.themeSet);try{localStorage.setItem("ny-theme",t.dataset.themeSet)}catch(x){}if(!guest)N.req("account/theme",{body:{theme:t.dataset.themeSet}}).catch(()=>{})});
/* password show/hide */
const sprite=meta("icons");
$$('input[type=password]').forEach(i=>{if(i.closest(".pw-wrap"))return;const w=d.createElement("span");w.className="pw-wrap";i.parentNode.insertBefore(w,i);w.append(i);const b=d.createElement("button");b.type="button";b.className="pw-toggle";b.setAttribute("aria-label","Show password");const ic=n=>`<svg class="ic"><use href="${sprite}#${n}"/></svg>`;b.innerHTML=ic("eye");b.addEventListener("click",()=>{const s=i.type==="password";i.type=s?"text":"password";b.innerHTML=ic(s?"eye-off":"eye");b.setAttribute("aria-label",s?"Hide password":"Show password");i.focus()});w.append(b)});
/* mobile nav */
d.addEventListener("click",e=>{if(e.target.closest("[data-nav-toggle]"))$("#app")?.classList.toggle("nav-open");else if(e.target.closest("[data-nav-close]"))$("#app")?.classList.remove("nav-open")});
/* close dropdowns on outside click / escape */
d.addEventListener("click",e=>{$$("details.dd[open]").forEach(x=>{if(!x.contains(e.target))x.removeAttribute("open")})});
d.addEventListener("keydown",e=>{if(e.key==="Escape")$$("details.dd[open]").forEach(x=>x.removeAttribute("open"));
if(e.key==="/"&&!/INPUT|TEXTAREA|SELECT/.test(d.activeElement?.tagName||"")&&!d.activeElement?.isContentEditable){const s=$("[data-search]");if(s){e.preventDefault();s.focus()}}});
/* sidebar sections: all open while the menu fits; when it overflows, sections collapse (the chevron expands one, remembered) and hovering a collapsed one shows a flyout */
const SB=$(".sidebar"),SECS=$$(".nav-sec"),secSet=(x,o)=>{x.classList.toggle("open",o);const tg=$("[data-sec-toggle]",x);if(tg)tg.setAttribute("aria-expanded",o?"true":"false")},secPref=x=>{try{return localStorage.getItem("ny-sec-"+x.dataset.sec)}catch(e){return null}};
const navFit=()=>{if(!SB||!SECS.length)return;SB.classList.remove("nav-compact");SECS.forEach(x=>{x.classList.remove("fly");secSet(x,true)});if(SB.scrollHeight<=SB.clientHeight+2)return;SB.classList.add("nav-compact");SECS.forEach(x=>secSet(x,secPref(x)==="1"||x.hasAttribute("data-active")))};
SECS.forEach(x=>{const h=$(".nav-sec-head",x),tg=$("[data-sec-toggle]",x),it=$(".nav-sec-items",x);
tg.addEventListener("click",e=>{e.stopPropagation();const o=!x.classList.contains("open");x.classList.remove("fly");secSet(x,o);try{localStorage.setItem("ny-sec-"+x.dataset.sec,o?"1":"0")}catch(e){}});
let t;const show=()=>{if(x.classList.contains("open")||innerWidth<=900)return;clearTimeout(t);x.classList.add("fly");const r=h.getBoundingClientRect(),hh=it.offsetHeight,top=Math.max(12,Math.min(r.top-8,innerHeight-hh-12));it.style.top=top+"px";it.style.maxHeight=(innerHeight-24)+"px"};
x.addEventListener("mouseenter",show);x.addEventListener("mouseleave",()=>{t=setTimeout(()=>x.classList.remove("fly"),150)})});
navFit();let navT;addEventListener("resize",()=>{clearTimeout(navT);navT=setTimeout(navFit,150)});
/* instant search: grouped results dropdown under the top-bar search (keyboard friendly) */
const sIn=$("[data-search]");if(sIn){const form=sIn.closest("form"),pop=d.createElement("div");pop.className="search-pop hide";pop.setAttribute("role","listbox");form.append(pop);let t,ctl,idx=-1;
const items=()=>$$(".sp-item",pop),mark=i=>{const a=items();idx=Math.max(-1,Math.min(i,a.length-1));a.forEach((x,k)=>x.classList.toggle("on",k===idx));a[idx]?.scrollIntoView({block:"nearest"})};
const close=()=>{pop.classList.add("hide");idx=-1};
const render=j=>{if(!j.groups.length){pop.innerHTML='<div class="sp-empty">No matches for “'+N.esc(j.q)+'”</div>';return}
pop.innerHTML=j.groups.map(g=>'<div class="sp-group" style="--gc:var(--'+N.esc(g.color)+')"><div class="sp-head"><span class="dot"></span>'+N.esc(g.label)+'<span class="n">'+g.count+'</span></div>'+g.items.slice(0,5).map(r=>'<a class="sp-item" role="option" href="'+N.esc(r.url_abs)+'"><span class="sp-ic"><svg class="ic"><use href="'+meta("icons")+'#'+N.esc(r.icon||g.icon)+'"/></svg></span><div><b>'+N.esc(r.title)+'</b>'+(r.meta?'<small>'+N.esc(r.meta)+'</small>':'')+'</div></a>').join("")+'</div>').join("")+'<a class="sp-foot" href="'+N.esc(j.all_url)+'"><span>See all '+j.total+' results</span><span class="kbd">Enter</span></a>'};
sIn.addEventListener("input",()=>{clearTimeout(t);const q=sIn.value.trim();if(q.length<2){close();return}t=setTimeout(async()=>{try{ctl?.abort();ctl=new AbortController();const r=await fetch(N.url("search")+"?q="+encodeURIComponent(q),{headers:{Accept:"application/json","X-Requested-With":"XMLHttpRequest"},signal:ctl.signal,credentials:"same-origin"});const j=await r.json();if(sIn.value.trim()!==q)return;render(j);pop.classList.remove("hide");idx=-1}catch(e){}},220)});
sIn.addEventListener("keydown",e=>{if(pop.classList.contains("hide"))return;if(e.key==="ArrowDown"){e.preventDefault();mark(idx+1)}else if(e.key==="ArrowUp"){e.preventDefault();mark(idx-1)}else if(e.key==="Enter"&&idx>=0){e.preventDefault();items()[idx].click()}else if(e.key==="Escape"){close()}});
sIn.addEventListener("focus",()=>{if(pop.innerHTML&&sIn.value.trim().length>1)pop.classList.remove("hide")});
d.addEventListener("click",e=>{if(!form.contains(e.target))close()})}
/* automatic scheduler: background beacon when the server asks for it */
if(meta("cron-due")&&navigator.sendBeacon){setTimeout(()=>{try{navigator.sendBeacon(N.url("cron/tick"))}catch(e){}},1500)}
/* toasts auto-hide */
$$(".toast").forEach(t=>setTimeout(()=>t.remove(),5000));
/* confirm forms & buttons */
d.addEventListener("submit",e=>{const f=e.target;if(f.dataset.confirm&&!confirm(f.dataset.confirm)){e.preventDefault();return}
const b=f.querySelector("[type=submit]");if(b&&!f.dataset.noLock){setTimeout(()=>{b.disabled=true},0);setTimeout(()=>{b.disabled=false},8000)}},true);
d.addEventListener("click",e=>{const a=e.target.closest("[data-confirm]:not(form)");if(a&&!confirm(a.dataset.confirm)){e.preventDefault();e.stopPropagation()}},true);
/* modals: <button data-modal="id">, <dialog id class="modal"> with [data-close] */
d.addEventListener("click",e=>{const o=e.target.closest("[data-modal]");if(o){e.preventDefault();const m=d.getElementById(o.dataset.modal);if(m){m.showModal();const f=m.querySelector("[autofocus],input:not([type=hidden]),select,textarea");f&&f.focus()}}
const c=e.target.closest("[data-close]");if(c)c.closest("dialog")?.close();
if(e.target.tagName==="DIALOG"&&e.target.classList.contains("modal")){const r=e.target.getBoundingClientRect();if(e.clientX<r.left||e.clientX>r.right||e.clientY<r.top||e.clientY>r.bottom)e.target.close()}});
$$("dialog[data-open]").forEach(m=>m.showModal());
/* auto-submit selects in filter bars */
d.addEventListener("change",e=>{if(e.target.matches("[data-autosubmit]"))e.target.form.requestSubmit()});
/* copy to clipboard */
d.addEventListener("click",e=>{const c=e.target.closest("[data-copy]");if(!c)return;e.preventDefault();navigator.clipboard.writeText(c.dataset.copy).then(()=>N.toast("Copied to clipboard"))});
/* notifications dropdown */
const nd=$("[data-notif]");if(nd){const load=async()=>{try{const j=await N.req("notifications/feed"),l=$("[data-notif-list]",nd);
l.innerHTML=j.items.length?j.items.map(n=>`<a class="notif ${n.read_at?"":"unread"}" href="${N.esc(n.url||N.url("notifications"))}" data-nid="${n.id}"><span class="notif-ic">🔔</span><span><b class="small">${N.esc(n.title)}</b><div class="xs muted">${N.esc(n.body||"")}</div><div class="xs muted">${N.esc(n.ago)}</div></span></a>`).join("")+(j.unread?'<button type="button" class="btn sm ghost mt" data-read-all>Mark all as read</button>':""):'<div class="muted small center" style="padding:16px">You are all caught up ✨</div>';
const dot=$("[data-notif-dot]",nd);if(!j.unread&&dot)dot.remove()}catch(e){}};
nd.addEventListener("toggle",()=>{if(nd.open)load()});
nd.addEventListener("click",e=>{const a=e.target.closest("[data-nid]");if(a)N.req("notifications/read",{body:{id:a.dataset.nid}}).catch(()=>{});if(e.target.closest("[data-read-all]")){e.preventDefault();N.req("notifications/read",{body:{all:1}}).then(load)}})}
/* tabs: [data-tabs] > [data-tab=id]; panels [data-panel=id] */
$$("[data-tabs]").forEach(t=>{const show=id=>{$$("[data-tab]",t).forEach(b=>b.classList.toggle("active",b.dataset.tab===id));$$("[data-panel]").forEach(p=>{if(p.dataset.group===t.dataset.tabs)p.classList.toggle("hide",p.dataset.panel!==id)});try{history.replaceState(null,"","#"+id)}catch(e){}};
t.addEventListener("click",e=>{const b=e.target.closest("[data-tab]");if(b){e.preventDefault();show(b.dataset.tab)}});const h=location.hash.slice(1);show($(`[data-tab="${h}"]`,t)?h:$("[data-tab]",t)?.dataset.tab)});
/* line items calculator: table[data-line-items] with template row; inputs name="items[i][field]" */
$$("[data-line-items]").forEach(tb=>{const body=$("tbody",tb),tpl=$("template",tb.parentElement)||$("template",tb),form=tb.closest("form");let idx=body.children.length;
const calc=()=>{let sub=0,tax=0;$$("tr",body).forEach(r=>{const q=+($("[data-f=quantity]",r)?.value||0),p=+($("[data-f=unit_price]",r)?.value||0),t=+($("[data-f=tax_rate]",r)?.value||0),a=q*p;sub+=a;tax+=a*t/100;const o=$("[data-f=amount]",r);if(o)o.textContent=N.fmtMoney(a)});
const disc=+(form.querySelector("[name=discount]")?.value||0),set=(k,v)=>{const el=form.querySelector(`[data-total=${k}]`);if(el)el.textContent=N.fmtMoney(v)};set("subtotal",sub);set("tax",tax);set("discount",disc);set("total",Math.max(0,sub+tax-disc))};
const add=()=>{const h=tpl.innerHTML.replace(/__i__/g,idx++);body.insertAdjacentHTML("beforeend",h);calc()};
form.addEventListener("input",calc);form.addEventListener("click",e=>{if(e.target.closest("[data-add-line]")){e.preventDefault();add()}const rm=e.target.closest("[data-remove-line]");if(rm){e.preventDefault();if(body.children.length>1){rm.closest("tr").remove();calc()}}});
if(!body.children.length)add();calc()});
/* simple fetch-forms: form[data-ajax] posts and shows toast, optional data-reload */
d.addEventListener("submit",async e=>{const f=e.target;if(!f.matches("form[data-ajax]"))return;e.preventDefault();const b=f.querySelector("[type=submit]");try{const j=await N.req(f.action,{body:new FormData(f)});N.toast(j.message||"Saved");if(f.dataset.reload!==undefined)location.reload();f.dispatchEvent(new CustomEvent("ajax:done",{detail:j,bubbles:true}))}catch(err){N.toast(err.message,"error")}finally{if(b)b.disabled=false}});
})();
