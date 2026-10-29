/* Niyu AI assistant drawer: open/close (Esc, focus return), conversations (DB, last one kept per tab), safe markdown-lite rendering, settings page helpers */
(()=>{"use strict";const d=document,N=window.Niyanta,$=(s,c=d)=>c.querySelector(s),$$=(s,c=d)=>[...c.querySelectorAll(s)];if(!N)return;
const sprite=($('meta[name="icons"]')||{}).content||"",ic=n=>`<svg class="ic sm"><use href="${sprite}#${n}"/></svg>`;
const ss={get(k){try{return sessionStorage.getItem(k)}catch(e){return null}},set(k,v){try{v==null?sessionStorage.removeItem(k):sessionStorage.setItem(k,v)}catch(e){}}};

/* ---------- markdown-lite: escape first, then allow a small safe subset ---------- */
const safeUrl=u=>{u=u.replace(/&amp;/g,"&");try{const x=new URL(u,location.href);if(x.origin!==location.origin)return null;let p=x.pathname+x.search+x.hash;if(N.base&&p.startsWith(N.base+"/"))p=p.slice(N.base.length);return/^\/(?!\/)/.test(p)?p:null}catch(e){return null}};
const inline=s=>{const code=[];s=s.replace(/`([^`\n]+)`/g,(m,c)=>{code.push(c);return"\u0000"+(code.length-1)+"\u0000"});
s=s.replace(/\[([^\]\n]{1,200})\]\(([^)\s]{1,300})\)/g,(m,t,u)=>{const p=safeUrl(u);return p?`<a href="${N.esc(N.url(p))}">${t}</a>`:t});
s=s.replace(/\*\*([^*\n]+)\*\*/g,"<b>$1</b>").replace(/(^|[\s(])\*([^*\s][^*\n]*?)\*(?=[\s).,;:!?]|$)/g,"$1<i>$2</i>").replace(/(^|[\s(])_([^_\s][^_\n]*?)_(?=[\s).,;:!?]|$)/g,"$1<i>$2</i>");
return s.replace(/\u0000(\d+)\u0000/g,(m,i)=>"<code>"+code[+i]+"</code>")};
const cells=l=>l.trim().replace(/^\||\|$/g,"").split("|").map(c=>c.trim());
const md=src=>{const L=N.esc(String(src||"").replace(/\r/g,"")).split("\n"),o=[];let i=0,para=[];const flush=()=>{if(para.length){o.push("<p>"+para.map(inline).join("<br>")+"</p>");para=[]}};
while(i<L.length){const l=L[i],t=l.trim();
if(/^```/.test(t)){flush();const b=[];i++;while(i<L.length&&!/^```/.test(L[i].trim()))b.push(L[i++]);i++;o.push("<pre><code>"+b.join("\n")+"</code></pre>");continue}
if(t.startsWith("|")&&i+1<L.length&&/^\|?\s*:?-{2,}/.test(L[i+1].trim())){flush();const h=cells(t);i+=2;const rows=[];while(i<L.length&&L[i].trim().startsWith("|"))rows.push(cells(L[i++]));
o.push('<div class="ai-tw"><table><thead><tr>'+h.map(c=>"<th>"+inline(c)+"</th>").join("")+"</tr></thead><tbody>"+rows.map(r=>"<tr>"+h.map((x,k)=>"<td>"+inline(r[k]||"")+"</td>").join("")+"</tr>").join("")+"</tbody></table></div>");continue}
let m;if((m=t.match(/^(#{1,6})\s+(.*)$/))){flush();o.push((m[1].length<=3?"<h4>":"<h5>")+inline(m[2])+(m[1].length<=3?"</h4>":"</h5>"));i++;continue}
if(/^(-{3,}|\*{3,})$/.test(t)){flush();o.push("<hr>");i++;continue}
if(/^[-*•]\s+/.test(t)||/^\d+[.)]\s+/.test(t)){flush();const ol=/^\d/.test(t),it=[];while(i<L.length&&(ol?/^\s*\d+[.)]\s+/:/^\s*[-*•]\s+/).test(L[i])){it.push(L[i].trim().replace(ol?/^\d+[.)]\s+/:/^[-*•]\s+/,""));i++}
o.push((ol?"<ol>":"<ul>")+it.map(x=>"<li>"+inline(x)+"</li>").join("")+(ol?"</ol>":"</ul>"));continue}
if(t===""){flush();i++;continue}para.push(t);i++}flush();return o.join("")};
N.md=md;

/* ---------- drawer ---------- */
const dr=$("[data-ai]");
if(dr){const msgs=$("[data-ai-msgs]",dr),scroll=$("[data-ai-scroll]",dr),welcome=$("[data-ai-welcome]",dr),typing=$("[data-ai-typing]",dr),form=$("[data-ai-form]",dr),input=$("[data-ai-input]",dr),send=$("[data-ai-send]",dr),scrim=$("[data-ai-scrim]"),menu=$("[data-ai-hist-menu]",dr),histBtn=$("[data-ai-hist]",dr),ready=dr.dataset.ready==="1";
let conv=+ss.get("ny-ai-conv")||null,loaded=null,busy=false,opener=null,tt;
const launchers=()=>$$("[data-ai-open]");
const chips=$("[data-ai-chips]",dr);if(chips){try{JSON.parse(dr.dataset.suggest||"[]").forEach(s=>{const b=d.createElement("button");b.type="button";b.className="ai-chip";b.textContent=s;b.addEventListener("click",()=>ask(s));chips.append(b)})}catch(e){}}
const bottom=()=>{scroll.scrollTop=scroll.scrollHeight};
const empty=()=>{welcome.hidden=!!msgs.children.length};
const bubble=(m)=>{const w=d.createElement("div");w.className="ai-msg "+m.role;
if(m.role==="user"){w.innerHTML='<div class="ai-bubble"></div>';$(".ai-bubble",w).textContent=m.content}
else{w.innerHTML='<span class="ai-ava sm"><img src="'+N.esc(N.url("assets/img/logo.svg"))+'" alt=""></span><div class="ai-col"><div class="ai-bubble md">'+(m.role==="error"?'<span class="ai-err">'+ic("alert")+N.esc(m.content)+"</span>":md(m.content))+"</div>"+
(m.role==="assistant"?'<div class="ai-meta">'+(m.tools&&m.tools.length?'<span class="ai-used" title="Data Niyu looked up">'+ic("database")+N.esc(m.tools.join(" · "))+"</span>":"")+'<button type="button" class="ai-copy" title="Copy answer" aria-label="Copy answer">'+ic("copy")+"</button></div>":"")+"</div>";
const cp=$(".ai-copy",w);if(cp)cp.addEventListener("click",()=>{navigator.clipboard?.writeText(m.content).then(()=>N.toast("Copied"))})}
msgs.append(w);empty();return w};
const reset=()=>{msgs.innerHTML="";loaded=null;empty()};
const setConv=id=>{conv=id||null;ss.set("ny-ai-conv",conv)};
const load=async id=>{if(!id||loaded===id)return;try{const j=await N.req("ai/conversations/"+id);msgs.innerHTML="";j.messages.forEach(bubble);loaded=id;empty();bottom()}catch(e){if(e.status===404){setConv(null);reset()}}};
const busyOn=on=>{busy=on;typing.hidden=!on;send.disabled=on||!ready;dr.classList.toggle("is-busy",on);clearTimeout(tt);if(on){const t=$("[data-ai-typing-text]",dr);t.textContent="Looking it up…";tt=setTimeout(()=>t.textContent="Still working on it…",9000)}if(on)bottom()};
const ask=async text=>{text=String(text||"").trim();if(!text||busy||!ready)return;bubble({role:"user",content:text});input.value="";grow();busyOn(true);
try{const j=await N.req("ai/chat",{body:{message:text,conversation_id:conv||""}});setConv(j.conversation.id);loaded=j.conversation.id;bubble(j.message)}
catch(e){bubble({role:"error",content:e.message||"Something went wrong. Please try again."});if(!input.value){input.value=text;grow()}}
finally{busyOn(false);bottom();input.focus()}};
const grow=()=>{input.style.height="36px";input.style.height=Math.min(input.scrollHeight,160)+"px"};
const open=()=>{if(!dr.hidden&&dr.classList.contains("open"))return;opener=d.activeElement;dr.hidden=false;scrim&&(scrim.hidden=false);requestAnimationFrame(()=>{dr.classList.add("open");scrim&&scrim.classList.add("open")});d.documentElement.classList.add("ai-on");
launchers().forEach(b=>b.setAttribute("aria-expanded","true"));ss.set("ny-ai-open","1");if(conv)load(conv);setTimeout(()=>(ready?input:$("[data-ai-close]",dr)).focus(),60);bottom()};
const close=()=>{dr.classList.remove("open");scrim&&scrim.classList.remove("open");d.documentElement.classList.remove("ai-on");launchers().forEach(b=>b.setAttribute("aria-expanded","false"));ss.set("ny-ai-open",null);hideMenu();
setTimeout(()=>{if(!dr.classList.contains("open")){dr.hidden=true;scrim&&(scrim.hidden=true)}},260);(opener&&opener!==d.body&&d.contains(opener)?opener:launchers()[0])?.focus()};
const hideMenu=()=>{menu.hidden=true;histBtn.setAttribute("aria-expanded","false")};
const showMenu=async()=>{menu.hidden=false;histBtn.setAttribute("aria-expanded","true");const l=$("[data-ai-hist-list]",menu);l.innerHTML='<div class="ai-hist-empty">Loading…</div>';
try{const j=await N.req("ai/conversations");l.innerHTML=j.items.length?"":'<div class="ai-hist-empty">No chats yet.</div>';
j.items.forEach(c=>{const r=d.createElement("div");r.className="ai-hist-item"+(c.id===conv?" on":"");r.innerHTML='<button type="button" class="ai-hist-open"><b></b><small></small></button><button type="button" class="ai-hist-del" title="Delete chat" aria-label="Delete chat">'+ic("trash")+"</button>";
$("b",r).textContent=c.title;$("small",r).textContent=c.ago;$(".ai-hist-open",r).addEventListener("click",()=>{hideMenu();setConv(c.id);loaded=null;load(c.id);input.focus()});
$(".ai-hist-del",r).addEventListener("click",async e=>{e.stopPropagation();if(!confirm("Delete this chat?"))return;try{await N.req("ai/conversations/"+c.id+"/delete",{body:{}});r.remove();if(c.id===conv){setConv(null);reset()}}catch(x){N.toast(x.message,"error")}});l.append(r)})}
catch(e){l.innerHTML='<div class="ai-hist-empty">'+N.esc(e.message)+"</div>"}};
d.addEventListener("click",e=>{if(e.target.closest("[data-ai-open]")){e.preventDefault();dr.classList.contains("open")?close():open()}
if(e.target.closest("[data-ai-close]")||e.target.closest("[data-ai-scrim]"))close();
if(e.target.closest("[data-ai-new]")){setConv(null);reset();hideMenu();input.focus()}
if(e.target.closest("[data-ai-hist]")){menu.hidden?showMenu():hideMenu()}else if(!e.target.closest("[data-ai-hist-menu]"))hideMenu()});
d.addEventListener("keydown",e=>{if(e.key==="Escape"&&dr.classList.contains("open")){if(!menu.hidden){hideMenu();histBtn.focus()}else close()}
if(e.key==="Tab"&&dr.classList.contains("open")&&innerWidth<=700){const f=$$('button:not([disabled]),textarea:not([disabled]),a[href]',dr).filter(x=>x.offsetParent);if(!f.length)return;const a=f[0],z=f[f.length-1];
if(e.shiftKey&&d.activeElement===a){e.preventDefault();z.focus()}else if(!e.shiftKey&&d.activeElement===z){e.preventDefault();a.focus()}}});
form.addEventListener("submit",e=>{e.preventDefault();ask(input.value)});
input.addEventListener("keydown",e=>{if(e.key==="Enter"&&!e.shiftKey&&!e.isComposing){e.preventDefault();ask(input.value)}});
input.addEventListener("input",grow);
/* deep link: ?ai=open[&c=id] */
const q=new URLSearchParams(location.search);if(q.get("ai")==="open"){const c=+q.get("c");if(c){setConv(c);loaded=null}q.delete("ai");q.delete("c");history.replaceState(null,"",location.pathname+(q.toString()?"?"+q:"")+location.hash);open()}
else if(ss.get("ny-ai-open")==="1"&&innerWidth>700)open();
empty()}

/* ---------- settings page: presets fill the model form; test connection ---------- */
const mf=$("[data-ai-model-form]");if(mf){const f=n=>mf.elements[n],dl=$("#ai-model-list"),pre=$$("[data-preset]",mf);
const apply=(p,keep)=>{pre.forEach(x=>x.classList.toggle("on",x===p));const v=p.dataset;f("preset").value=v.preset;$("[data-custom-only]",mf).hidden=v.preset!=="custom";$("[data-key-row]",mf).hidden=v.provider==="mock";
dl.innerHTML="";JSON.parse(v.models||"[]").forEach(m=>{const o=d.createElement("option");o.value=m;dl.append(o)});$("[data-preset-hint]",mf).textContent=v.hint||"";
if(!keep){f("base_url").value=v.url||"";f("model").value=JSON.parse(v.models||"[]")[0]||"";if(!f("label").dataset.touched)f("label").value=v.name||"";f("base_url").placeholder=v.url||"https://example.com/v1"}
f("api_key").placeholder=f("api_key").dataset.saved==="1"?"•••• saved, leave blank to keep":(v.needsKey==="1"?"Paste your API key":"Optional")};
pre.forEach(p=>p.addEventListener("click",()=>apply(p,false)));f("label").addEventListener("input",()=>{f("label").dataset.touched="1"});
const dlg=mf.closest("dialog"),title=$("[data-model-title]",dlg);
const fill=(m)=>{mf.reset();f("label").dataset.touched=m?"1":"";mf.action=N.url(m?"ai/models/"+m.id:"ai/models");title.textContent=m?"Edit model":"Add a model";f("api_key").dataset.saved=m&&m.has_key?"1":"";$("[data-clear-key]",mf).hidden=!(m&&m.has_key);
const p=pre.find(x=>x.dataset.preset===(m?m.preset:"openai"))||pre[0];apply(p,!!m);if(m){f("label").value=m.label;f("base_url").value=m.base_url||"";f("model").value=m.model;f("max_tokens").value=m.max_tokens||"";f("temperature").value=m.temperature??"";f("provider").value=m.provider==="mock"?"openai_compatible":m.provider;f("inactive").checked=!+m.active;f("is_default").checked=!!+m.is_default}};
d.addEventListener("click",e=>{const a=e.target.closest("[data-model-add]"),ed=e.target.closest("[data-model-edit]");if(a){fill(null);dlg.showModal();f("label").focus()}if(ed){fill(JSON.parse(ed.dataset.modelEdit));dlg.showModal();f("label").focus()}});
if(dlg.hasAttribute("data-open")){const p=pre.find(x=>x.dataset.preset===f("preset").value)||pre[0];apply(p,true)}}
$$("[data-model-test]").forEach(b=>b.addEventListener("click",async()=>{const out=$("[data-test-out='"+b.dataset.modelTest+"']");b.disabled=true;out.className="ai-test small muted";out.textContent="Testing…";
try{const j=await N.req("ai/models/"+b.dataset.modelTest+"/test",{body:{}});out.className="ai-test small ok";out.textContent=j.message}catch(e){out.className="ai-test small bad";out.textContent=e.message}finally{b.disabled=false}}));
})();
