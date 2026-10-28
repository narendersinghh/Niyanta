/* Niyanta CRM: journey/contact modals (add + edit), tab deep links, client→project filter, file picker label */
(()=>{"use strict";const d=document,$=(s,c=d)=>c.querySelector(s),$$=(s,c=d)=>[...c.querySelectorAll(s)];
const fill=(f,data)=>{Object.entries(data).forEach(([k,v])=>{if(k==="action")return;$$(`[name="${k}"]`,f).forEach(el=>{if(el.type==="checkbox")el.checked=!!Number(v);else if(el.type==="radio")el.checked=el.value===String(v);else el.value=v??""})})};
const prep=(f,edit,data)=>{f.reset();const t=$("[data-modal-title]",f);if(t)t.textContent=edit?t.dataset.edit:t.dataset.new;f.action=edit?data.action:f.dataset.newAction;
$$("[data-system-type]",f).forEach(l=>l.classList.toggle("hide",!edit||$("input",l).value!==data.type));const oc=$("[name=occurred_at]",f);if(oc&&!edit)oc.value=oc.dataset.default;if(edit)fill(f,data)};
d.addEventListener("click",e=>{let b=e.target.closest("[data-activity-new],[data-activity]"),f=$("[data-activity-form]");
if(b&&f){prep(f,b.hasAttribute("data-activity"),b.dataset.activity?JSON.parse(b.dataset.activity):{});return}
b=e.target.closest("[data-contact-new],[data-contact]");f=$("[data-contact-form]");if(b&&f){prep(f,b.hasAttribute("data-contact"),b.dataset.contact?JSON.parse(b.dataset.contact):{});return}
b=e.target.closest("[data-tab-link]");if(b){const t=$(`[data-tab="${b.dataset.tabLink}"]`);if(t){e.preventDefault();b.closest("details")?.removeAttribute("open");t.click();t.scrollIntoView({behavior:"smooth",block:"start"})}}});
/* project select follows the chosen client */
const cs=$("[data-client-select]"),ps=$("[data-project-select]");
if(cs&&ps){const sync=()=>{const c=cs.value;let any=false;$$("option[data-client]",ps).forEach(o=>{const ok=o.dataset.client===c;o.hidden=!ok;o.disabled=!ok;any=any||ok;if(!ok&&o.selected)ps.value=""});ps.closest(".field")?.classList.toggle("crm-muted-field",!any)};cs.addEventListener("change",sync);sync()}
/* file picker shows chosen name */
d.addEventListener("change",e=>{const i=e.target.closest(".crm-drop input[type=file]");if(i){const b=$("b",i.parentElement);if(b)b.textContent=i.files.length?i.files[0].name:"Choose a file"}});
})();
