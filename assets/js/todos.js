/* Tasks & routines: AJAX tick (complete / reopen) that moves rows without a reload; plain POST still works without JS */
(()=>{"use strict";const d=document,N=window.Niyanta;if(!N)return;
const recount=()=>{d.querySelectorAll("[data-todo-group]").forEach(g=>{const n=g.querySelectorAll("[data-todo]").length;if(!n)g.remove();else{const c=g.querySelector("[data-todo-count]");if(c)c.textContent=n}});
const gs=d.querySelector("[data-todo-groups]");if(gs&&!gs.querySelector("[data-todo-group]"))gs.querySelector("[data-todo-clear]")?.classList.remove("hide");
const dn=d.querySelector("[data-todo-done]");if(dn){const n=dn.querySelectorAll("[data-todo]").length,c=dn.querySelector("[data-todo-done-count]");if(c)c.textContent=n;dn.hidden=!n}};
const html=s=>{const t=d.createElement("template");t.innerHTML=String(s||"").trim();return t.content.firstElementChild};
d.addEventListener("submit",async e=>{const f=e.target;if(!f.matches("form[data-todo-tick]")||e.defaultPrevented)return;e.preventDefault();
const row=f.closest("[data-todo]"),reopen=f.dataset.todoTick==="reopen";if(!row)return;row.classList.add("busy");
try{const j=await N.req(f.action,{body:new FormData(f)}),nr=html(j.html),list=d.querySelector("[data-todo-done-list]");
if(row.closest("[data-todo-widget]")){row.classList.add("is-done","gone");setTimeout(()=>row.remove(),350)}
else if(!reopen&&list&&row.closest("[data-todo-group]")){row.remove();if(nr)list.prepend(nr);recount()}
else if(reopen&&row.closest("[data-todo-done]")){location.reload();return}
else if(nr){row.replaceWith(nr)}
N.toast(j.message||"Saved")}catch(err){row.classList.remove("busy");N.toast(err.message,"error")}})})();
