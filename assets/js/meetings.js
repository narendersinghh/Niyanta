/* Niyanta Meetings: "Add transcript or notes" in the Log activity dialog (shown for type Meeting), AJAX preview with detected client/project, Fireflies picker, sort/upload client-project selects, action item options */
(()=>{"use strict";const d=document,$=(s,c=d)=>c.querySelector(s),$$=(s,c=d)=>[...c.querySelectorAll(s)],N=window.Niyanta,esc=s=>N.esc(s);
const conf={high:["mint","High confidence"],medium:["lemon","Medium confidence"],low:["peach","Low confidence"],none:["gray","No match"]};
$$("[data-meeting-attach]").forEach(sec=>{const form=sec.closest("form");if(!form)return;const standalone=sec.hasAttribute("data-standalone"),box=$("[data-mt-result]",sec);let timer=null,seq=0;
const vis=()=>{if(standalone)return;const t=$("input[name=type]:checked",form);sec.classList.toggle("hide",!t||t.value!=="meeting")};
const clearInputs=keep=>{if(keep!=="paste"){const t=$("[name=mt_text]",sec);if(t)t.value=""}if(keep!=="file"){const f=$("[name=mt_file]",sec);if(f){f.value="";const l=$("[data-mt-file-label]",sec);if(l)l.textContent="Choose a transcript file"}}if(keep!=="fireflies"){const h=$("[name=mt_fireflies_id]",sec);if(h)h.value="";$$(".mt-ff-item",sec).forEach(b=>b.classList.remove("on"))}};
const mode=m=>{$$("[data-mt-mode]",sec).forEach(b=>b.classList.toggle("on",b.dataset.mtMode===m));$$("[data-mt-panel]",sec).forEach(p=>p.classList.toggle("hide",p.dataset.mtPanel!==m));clearInputs(m);box.classList.add("hide")};
const field=n=>form.querySelector(`[name="${n}"]`);
const fill=(el,v)=>{if(!el||v==null||v==="")return;if(!el.value||el.dataset.mtAuto==="1"||(el.dataset.default&&el.value===el.dataset.default)){el.value=v;el.dataset.mtAuto="1"}};
const pick=(sel,id)=>{if(!sel||sel.dataset.mtUser==="1"||!id)return;const o=[...sel.options].find(x=>x.value===String(id)&&!x.disabled);if(o){sel.value=o.value;sel.dispatchEvent(new Event("change",{bubbles:true}))}};
const render=j=>{const c=conf[j.confidence]||conf.none,facts=[j.meeting_label,j.duration_min&&j.duration_min+" min",j.participant_count&&j.participant_count+" participant"+(j.participant_count===1?"":"s"),j.tool,(j.chars||0).toLocaleString()+" characters"].filter(Boolean);
const match=[j.client&&j.client.name,j.project&&j.project.name].filter(Boolean).join(" › ");
box.innerHTML='<div class="mt-facts">'+facts.map(f=>'<span class="pill pill-gray">'+esc(f)+"</span>").join("")+'</div><div class="mt-match">Detected: '+(match?esc(match):'<span class="muted">no client or project</span>')+' <span class="pill pill-'+c[0]+'">'+c[1]+"</span></div>"
+(j.reasons&&j.reasons.length?"<ul>"+j.reasons.map(r=>"<li>"+esc(r)+"</li>").join("")+"</ul>":"")+(j.warnings||[]).map(w=>'<div class="mt-warn">'+esc(w)+"</div>").join("")
+(j.duplicate?'<div class="mt-dup">Already logged: <a href="'+esc(j.duplicate.url)+'">'+esc(j.duplicate.title)+"</a>. Saving opens that entry instead of creating a second one.</div>":"")+'<div class="muted">'+esc(j.notice||"")+" You can change the detected fields before saving.</div>";
box.classList.remove("hide","loading")};
const preview=async()=>{const fd=new FormData(),t=$("[name=mt_text]",sec),f=$("[name=mt_file]",sec),h=$("[name=mt_fireflies_id]",sec);
if(f&&f.files.length)fd.append("mt_file",f.files[0]);else if(h&&h.value)fd.append("mt_fireflies_id",h.value);else if(t&&t.value.trim().length>=30)fd.append("mt_text",t.value);else{box.classList.add("hide");return}
["mt_ctx_client","mt_ctx_project"].forEach(n=>{const v=$(`[name=${n}]`,sec);if(v&&v.value)fd.append(n,v.value)});
const my=++seq;box.classList.remove("hide");box.classList.add("loading");box.textContent="Reading the notes and looking for the client and project…";
try{const j=await N.req(sec.dataset.preview,{body:fd});if(my!==seq)return;render(j);fill(field("title"),j.title);fill(field("occurred_at"),j.meeting_at);
if(j.project)pick(form.querySelector("[data-meeting-project]"),j.project.id);
if(j.client)pick(form.querySelector("[data-mt-client]"),j.client.id);if(j.project)pick(form.querySelector("[data-mt-project]"),j.project.id)}
catch(e){if(my!==seq)return;box.classList.remove("loading");box.innerHTML='<div class="mt-warn">'+esc(e.message)+"</div>"}};
sec.addEventListener("click",async e=>{const m=e.target.closest("[data-mt-mode]");if(m){mode(m.dataset.mtMode);return}
const ld=e.target.closest("[data-mt-ff-load]");if(ld){ld.disabled=true;const list=$("[data-mt-ff-list]",sec);try{const j=await N.req(sec.dataset.ff);list.innerHTML=(j.items||[]).length?j.items.map(x=>'<button type="button" class="mt-ff-item" data-id="'+esc(x.id)+'"><b>'+esc(x.title)+'</b><span class="xs muted">'+esc([x.label,x.duration?x.duration+" min":""].filter(Boolean).join(" · "))+"</span></button>").join(""):'<p class="xs muted">No Fireflies meetings found yet.</p>'}catch(err){list.insertAdjacentHTML("beforeend",'<p class="xs mt-warn">'+esc(err.message)+"</p>");ld.disabled=false}return}
const it=e.target.closest(".mt-ff-item");if(it){$$(".mt-ff-item",sec).forEach(b=>b.classList.toggle("on",b===it));$("[name=mt_fireflies_id]",sec).value=it.dataset.id;preview()}});
sec.addEventListener("input",e=>{if(e.target.name==="mt_text"){clearTimeout(timer);timer=setTimeout(preview,900)}});
sec.addEventListener("change",e=>{if(e.target.name==="mt_file"){const l=$("[data-mt-file-label]",sec);if(l)l.textContent=e.target.files.length?e.target.files[0].name:"Choose a transcript file";preview()}});
form.addEventListener("change",e=>{if(e.target.name==="type")vis();if(e.isTrusted&&e.target.matches("[data-meeting-project],[data-mt-project],[data-mt-client]"))e.target.dataset.mtUser="1"});
form.addEventListener("input",e=>{if(e.target.matches("[name=title],[name=occurred_at]"))e.target.dataset.mtAuto=""});
form.addEventListener("reset",()=>setTimeout(()=>{mode("paste");box.innerHTML="";$$("[data-mt-auto],[data-mt-user]",form).forEach(x=>{x.dataset.mtAuto="";x.dataset.mtUser=""});vis()},0));
d.addEventListener("click",e=>{if(e.target.closest("[data-activity-new],[data-activity]"))setTimeout(vis,0)});vis()});
/* client select filters the project select; a project picks its client (sort forms, upload dialog) */
const pair=root=>{const cs=$("[data-mt-client]",root),ps=$("[data-mt-project]",root);if(!cs||!ps)return;
const sync=()=>{const c=cs.value;$$("option[data-client]",ps).forEach(o=>{const ok=!c||o.dataset.client===c;o.hidden=!ok;o.disabled=!ok;if(!ok&&o.selected)ps.value=""})};
cs.addEventListener("change",sync);ps.addEventListener("change",()=>{const o=ps.selectedOptions[0];if(o&&o.dataset.client&&o.dataset.client!=="0"&&cs.value!==o.dataset.client){cs.value=o.dataset.client;sync()}});sync()};
$$("[data-mt-assign]").forEach(pair);$$("#mt-upload form").forEach(pair);
/* action items: task or to-do picks the matching assignee list */
d.addEventListener("change",e=>{const k=e.target.closest("[data-mt-kind]");if(!k)return;const box=k.closest(".mt-action-opts");$$("[data-mt-for]",box).forEach(s=>{const on=s.dataset.mtFor===k.value;s.disabled=!on;s.classList.toggle("hide",!on)})});
})();
