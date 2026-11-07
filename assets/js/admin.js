/* Niyanta admin: live appearance preview, email provider presets, unsaved-changes bar, typed confirmations */
(()=>{"use strict";const d=document,$=(s,c=d)=>c.querySelector(s),$$=(s,c=d)=>[...c.querySelectorAll(s)];
const box=$("[data-preview-box]"),setVar=el=>{if(!box||!el.dataset.var)return;box.style.setProperty(el.dataset.var,el.value+(el.dataset.unit||""))};
const fonts={};const loadFont=f=>{if(!f||f==="System"||fonts[f])return;fonts[f]=1;const l=d.createElement("link");l.rel="stylesheet";l.href="https://fonts.googleapis.com/css2?family="+encodeURIComponent(f).replace(/%20/g,"+")+":wght@400;600;700;800&display=swap";d.head.append(l)};
const pvState=t=>{const p=$("[data-pv-state]");if(p)p.textContent=t};
const sync=el=>{if(el.type==="color"){const w=el.closest(".color-field"),h=w&&w.querySelector("[data-hex]");if(h)h.textContent=el.value;if(w)w.classList.toggle("is-default",el.value.toLowerCase()===(el.dataset.default||""));setVar(el)}
else if(el.type==="range"){const o=el.parentElement.querySelector("output");if(o)o.textContent=el.value+(el.dataset.unit||"");setVar(el)}
const p=el.dataset.preview;if(p&&box){if(p==="font"){loadFont(el.value);box.style.setProperty("--font",(el.value==="System"?"system-ui":"'"+el.value.replace(/[^\w ]/g,"")+"'")+",system-ui,sans-serif")}else{const t=$(`[data-pv="${p}"]`,box);if(t)t.textContent=el.value}}};
/* unsaved changes indicator on the sticky save bar */
const dirty=f=>{if(!f||!f.matches("[data-dirty-watch]"))return;const b=$(".save-bar",f);if(!b||b.classList.contains("dirty"))return;b.classList.add("dirty");const t=$("[data-dirty-text]",b);if(t)t.textContent="Unsaved changes";pvState("Unsaved")};
d.addEventListener("input",e=>{if(e.target.matches("input,select,textarea")){sync(e.target);dirty(e.target.form)}});
d.addEventListener("change",e=>{const el=e.target;if(el.matches("select[data-preview]"))sync(el);if(el.matches("input,select,textarea"))dirty(el.form);
if(el.matches("[data-logo-input]")&&box&&el.files&&el.files[0]){const i=$("[data-pv-logo]",box);if(i){const img=d.createElement("img");img.alt="";img.src=URL.createObjectURL(el.files[0]);i.replaceChildren(img)}}
if(el.matches("[data-provider]"))provider(el.value,true)});
d.addEventListener("click",e=>{const r=e.target.closest("[data-reset-colors]");if(!r)return;e.preventDefault();let v={};try{v=JSON.parse(r.dataset.resetColors)}catch(x){}
Object.entries(v).forEach(([k,val])=>{const el=d.getElementById("f-"+k.replace(/\./g,"-"));if(el){el.value=val;sync(el);dirty(el.form)}})});
/* email provider presets: fill host/port/encryption, username hint, show matching instructions, hide SMTP for PHP mail() */
const pg=$("[data-presets]");let presets={};try{presets=pg?JSON.parse(pg.dataset.presets):{}}catch(x){}
const provider=(key,fill)=>{const p=presets[key];if(!p)return;$$("[data-prov-help]").forEach(h=>h.classList.toggle("hide",h.dataset.provHelp!==key));
$$("[data-show-when=smtp]").forEach(s=>s.classList.toggle("hide",key==="mail"));
const set=(id,v)=>{const el=d.getElementById(id);if(el&&v!==null&&v!==undefined&&v!=="")el.value=v};
if(fill){set("f-email-smtp_host",p.host);set("f-email-smtp_port",p.port||"");set("f-email-smtp_encryption",p.enc);const u=d.getElementById("f-email-smtp_username");if(u){if(p.user)u.value=p.user;else if(u.value==="apikey")u.value=""}}
const u=$("[data-smtp-user]");if(u)u.placeholder=p.uh||"";$$("[data-cred-hint]").forEach(h=>{h.textContent=p[h.dataset.credHint]||""})};
const cur=$("[data-provider]:checked");if(cur)provider(cur.value,false);
/* dependent settings fields: [data-when="s[key]"] shows while that toggle is on, or with data-when-value while that field has the value; a field inside a hidden one stays hidden */
const whenSync=()=>{for(let i=0;i<3;i++)$$("[data-when]").forEach(w=>{const f=w.closest("form"),els=f?[...f.elements].filter(x=>x.name===w.dataset.when):[];const el=els.find(x=>x.type!=="hidden")||els[0];if(!el)return;const host=el.closest("[data-when]"),v=el.type==="checkbox"?(el.checked?"1":""):el.value;
w.classList.toggle("hide",!!(host&&host.classList.contains("hide"))||("whenValue" in w.dataset?v!==w.dataset.whenValue:v!=="1"))})};
if($("[data-when]")){d.addEventListener("change",e=>{if(e.target.matches("input,select"))whenSync()});whenSync()}
/* typed confirmation: form[data-typed="WORD"] enables its submit button only when [data-typed-input] equals WORD */
$$("form[data-typed]").forEach(f=>{const i=$("[data-typed-input]",f),b=$("[type=submit]",f);if(!i||!b)return;const chk=()=>{b.disabled=i.value.trim()!==f.dataset.typed};i.addEventListener("input",chk);chk();
f.addEventListener("submit",ev=>{if(i.value.trim()!==f.dataset.typed){ev.preventDefault();return}f.dataset.noLock="1";b.disabled=true;b.innerHTML='<span class="spinner"></span> Working… keep this tab open'})});
/* long-running posts (demo import, cleanup): show progress on the button */
d.addEventListener("submit",e=>{const f=e.target;if(e.defaultPrevented||!f.matches("[data-busy]"))return;const b=$("[type=submit]",f);if(b){f.dataset.noLock="1";setTimeout(()=>{b.disabled=true;b.innerHTML='<span class="spinner"></span> '+f.dataset.busy},0)}});
if(box)$$("select[data-preview=font]").forEach(el=>loadFont(el.value));
/* users: delete dialog gets the row's URL and name */
const df=$("[data-del-form]");
if(df)d.addEventListener("click",e=>{const b=e.target.closest("[data-del-url]");if(!b)return;df.action=b.dataset.delUrl;df.reset();const n=$("[data-del-target]",df);if(n)n.textContent=b.dataset.delName;const dd=b.closest("details");if(dd)dd.open=false},true);
/* feature on/off switches (Settings › Features and each feature's settings header): confirm, revert when cancelled */
d.addEventListener("change",e=>{const c=e.target;if(!c.matches("[data-feature-switch]"))return;const m=c.checked?c.dataset.confirmOn:c.dataset.confirmOff;if(m&&!confirm(m)){c.checked=!c.checked;return}c.form.requestSubmit()});
})();
