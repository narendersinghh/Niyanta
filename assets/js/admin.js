/* Niyanta admin: live appearance preview, conditional fields, typed confirmations */
(()=>{"use strict";const d=document,$=(s,c=d)=>c.querySelector(s),$$=(s,c=d)=>[...c.querySelectorAll(s)];
const box=$("[data-preview-box]"),setVar=el=>{if(!box||!el.dataset.var)return;box.style.setProperty(el.dataset.var,el.value+(el.dataset.unit||""))};
const fonts={};const loadFont=f=>{if(!f||f==="System"||fonts[f])return;fonts[f]=1;const l=d.createElement("link");l.rel="stylesheet";l.href="https://fonts.googleapis.com/css2?family="+encodeURIComponent(f).replace(/%20/g,"+")+":wght@400;600;700;800&display=swap";d.head.append(l)};
const sync=el=>{if(el.type==="color"){const h=el.parentElement.querySelector("[data-hex]");if(h)h.textContent=el.value;setVar(el)}
else if(el.type==="range"){const o=el.parentElement.querySelector("output");if(o)o.textContent=el.value+(el.dataset.unit||"");setVar(el)}
const p=el.dataset.preview;if(p&&box){if(p==="font"){loadFont(el.value);box.style.setProperty("--font",(el.value==="System"?"system-ui":"'"+el.value.replace(/[^\w ]/g,"")+"'")+",system-ui,sans-serif")}else{const t=$(`[data-pv="${p}"]`,box);if(t)t.textContent=el.value}}};
d.addEventListener("input",e=>{if(e.target.matches("input,select"))sync(e.target)});
d.addEventListener("change",e=>{const el=e.target;if(el.matches("select[data-preview]"))sync(el);
if(el.matches("[data-logo-input]")&&box&&el.files&&el.files[0]){const i=$("[data-pv-logo]",box);if(i){const img=d.createElement("img");img.alt="";img.src=URL.createObjectURL(el.files[0]);i.replaceChildren(img)}}
if(el.matches("[data-driver]"))cond()});
d.addEventListener("click",e=>{const r=e.target.closest("[data-reset-colors]");if(!r)return;e.preventDefault();let v={};try{v=JSON.parse(r.dataset.resetColors)}catch(x){}
Object.entries(v).forEach(([k,val])=>{const el=d.getElementById("f-"+k.replace(/\./g,"-"));if(el){el.value=val;sync(el)}})});
/* conditional SMTP fields */
const cond=()=>{const s=$("[data-driver]");if(!s)return;$$("[data-show-when]").forEach(f=>f.classList.toggle("hide",f.dataset.showWhen!==s.value))};cond();
/* typed confirmation: form[data-typed="WORD"] enables its submit button only when [data-typed-input] equals WORD */
$$("form[data-typed]").forEach(f=>{const i=$("[data-typed-input]",f),b=$("[type=submit]",f);if(!i||!b)return;const chk=()=>{b.disabled=i.value.trim()!==f.dataset.typed};i.addEventListener("input",chk);chk();
f.addEventListener("submit",ev=>{if(i.value.trim()!==f.dataset.typed){ev.preventDefault();return}f.dataset.noLock="1";b.disabled=true;b.innerHTML='<span class="spinner"></span> Working… keep this tab open'})});
/* initial preview state */
if(box)$$("input[data-var],select[data-preview=font]").forEach(el=>{if(el.matches("select"))loadFont(el.value)});
})();
