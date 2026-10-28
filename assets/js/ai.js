/* Niyanta AI Assistant: tool forms -> JSON, escaped chat-style output, approval helpers, settings test. Model text is only ever set via textContent / value. */
(()=>{"use strict";const d=document,N=window.Niyanta;if(!N)return;const $=(s,c=d)=>c.querySelector(s),$$=(s,c=d)=>[...c.querySelectorAll(s)];
const run=async f=>{const box=f.parentElement.querySelector("[data-ai-result]");if(!box)return;const out=$("[data-ai-out]",box),meta=$("[data-ai-meta]",box),cp=$("[data-ai-copy]",box),btn=f.querySelector("[type=submit]");
box.classList.remove("hide");out.classList.remove("ai-err");out.innerHTML='<span class="ai-typing" aria-label="Thinking"><i></i><i></i><i></i></span>';meta.textContent="thinking…";cp.disabled=true;cp.dataset.copy="";
$$(".ai-followup,[data-ai-noemail]",box).forEach(x=>x.classList.add("hide"));if(btn)btn.disabled=true;box.scrollIntoView({behavior:"smooth",block:"nearest"});
try{const j=await N.req(f.action,{body:new FormData(f)});if(j.redirect){out.textContent=j.text||"Done.";meta.textContent="opening review…";location.href=j.redirect;return}
out.textContent=j.text||"";meta.textContent=[j.model,j.tokens?Number(j.tokens).toLocaleString()+" tokens":""].filter(Boolean).join(" · ");cp.dataset.copy=j.text||"";cp.disabled=false;
const fill=x=>{$$("[data-ai-fill]",x).forEach(i=>{i.value=j[i.dataset.aiFill]??""});$$("[data-ai-fill-text]",x).forEach(i=>{i.textContent=j[i.dataset.aiFillText]||""});x.classList.remove("hide")};
const sv=$("[data-ai-save]",box);if(sv&&j.can_save)fill(sv);const q=$("[data-ai-queue]",box);if(q&&j.can_queue)fill(q);if(j.no_email)$("[data-ai-noemail]",box)?.classList.remove("hide")}
catch(e){out.classList.add("ai-err");out.textContent=e.message||"Something went wrong. Please try again.";meta.textContent="could not complete";N.toast(e.message||"Request failed","error")}
finally{if(btn)setTimeout(()=>{btn.disabled=false},0)}};
d.addEventListener("submit",e=>{const f=e.target.closest("form[data-ai-tool]");if(!f)return;e.preventDefault();run(f)});
d.addEventListener("click",e=>{const r=e.target.closest("[data-ai-regen]");if(!r)return;const f=r.closest(".modal-body")?.querySelector("form[data-ai-tool]");if(f&&f.reportValidity())run(f)});
/* approval screen: include toggles + select all/none */
const sync=c=>c.closest(".ai-task")?.classList.toggle("off",!c.checked),count=()=>{const n=$$("[data-ai-include]:checked").length,el=$("[data-ai-count]");if(el)el.textContent=n+" selected";const b=$("[data-ai-approve]");if(b)b.disabled=!n};
$$("[data-ai-include]").forEach(sync);count();
d.addEventListener("change",e=>{if(e.target.matches("[data-ai-include]")){sync(e.target);count()}});
d.addEventListener("click",e=>{const a=e.target.closest("[data-ai-all]");if(!a)return;e.preventDefault();const on=a.dataset.aiAll==="1";$$("[data-ai-include]").forEach(c=>{c.checked=on;sync(c)});count()});
/* settings: connection test */
d.addEventListener("submit",async e=>{const f=e.target.closest("form[data-ai-test]");if(!f)return;e.preventDefault();const o=$("[data-ai-test-out]"),b=f.querySelector("[type=submit]");o.classList.remove("hide","ai-err");o.innerHTML='<span class="ai-typing"><i></i><i></i><i></i></span>';
try{const j=await N.req(f.action,{body:new FormData(f)});o.textContent=j.text+(j.tokens?" ("+j.tokens+" tokens)":"")}catch(err){o.classList.add("ai-err");o.textContent=err.message}finally{if(b)setTimeout(()=>{b.disabled=false},0)}});
})();
