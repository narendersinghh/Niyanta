/* Niyanta rich text editor (project brief): contenteditable + toolbar, shortcuts, paste/drop cleaning, image upload as project files, local draft, save. The server sanitizes every save again (Core\Html). */
(()=>{"use strict";const d=document,f=d.querySelector("[data-rte]");if(!f)return;const N=window.Niyanta,q=(s,c=f)=>c.querySelector(s),qa=(s,c=f)=>[...c.querySelectorAll(s)];
const area=q("[data-rte-area]"),bar=q(".rte-bar"),st=q("[data-rte-status]"),key=f.dataset.key,fileIn=q("[data-rte-file]"),tblBar=q("[data-rte-table]"),draftBox=q("[data-rte-draft]"),btns=()=>qa(".rte-b",bar).filter(b=>!b.closest("[hidden]"));
let version=f.dataset.version||"",saved="",dirty=false,busy=false,leaving=false,timer=0,last=null,inner=false;
try{d.execCommand("defaultParagraphSeparator",false,"p");d.execCommand("styleWithCSS",false,false)}catch(e){}
const store={get(){try{return JSON.parse(localStorage.getItem(key)||"null")}catch(e){return null}},set(v){try{localStorage.setItem(key,JSON.stringify(v))}catch(e){}},del(){try{localStorage.removeItem(key)}catch(e){}}};
/* ---- cleaning (mirror of Core\Html: allowed tags/attributes only) ---- */
const ALLOW=new Set("P BR H2 H3 H4 STRONG B EM I U S DEL UL OL LI BLOCKQUOTE PRE CODE A HR TABLE THEAD TBODY TR TH TD IMG".split(" ")),MAP={H1:"H2",H5:"H4",H6:"H4",STRIKE:"S",INS:"U",TT:"CODE",KBD:"CODE",SAMP:"CODE",MARK:"STRONG",TFOOT:"TBODY"},
DROP=new Set("SCRIPT STYLE IFRAME FRAME OBJECT EMBED APPLET SVG MATH TEMPLATE NOSCRIPT HEAD TITLE META LINK BASE TEXTAREA SELECT OPTION BUTTON INPUT AUDIO VIDEO CANVAS PICTURE SOURCE MAP COLGROUP COL CAPTION".split(" ")),
BLK=new Set("P H1 H2 H3 H4 H5 H6 UL OL LI BLOCKQUOTE PRE HR TABLE DIV SECTION ARTICLE HEADER FOOTER MAIN ASIDE NAV FIGURE DL FORM ADDRESS CENTER".split(" ")),
baseRx=new RegExp("^"+N.base.replace(/[.*+?^${}()|[\]\\]/g,"\\$&")+"/files/\\d+(/view)?$"),
appFile=u=>{try{const x=new URL(u,location.href);return x.origin===location.origin&&!x.search&&!x.hash&&baseRx.test(x.pathname)}catch(e){return false}},
okHref=h=>/^https?:\/\/[^\s<>"\\]+$/i.test(h)&&(()=>{try{return!!new URL(h).hostname}catch(e){return false}})()||/^mailto:[^@\s<>"]+@[^@\s<>"]+$/i.test(h)||appFile(h),
empty=el=>!el.textContent.replace(/\u00a0/g," ").trim()&&!el.querySelector("img,hr,table");
const walk=(from,to,pre)=>{for(const n of [...from.childNodes]){if(n.nodeType===3){to.append(pre?n.data:n.data.replace(/[\r\n\t ]+/g," "));continue}if(n.nodeType!==1)continue;
let t=n.tagName.toUpperCase();const s=n.getAttribute("style")||"";if(/mso-list\s*:\s*ignore/i.test(s))continue;if(t.includes(":")){walk(n,to,pre);continue}if(DROP.has(t))continue;t=MAP[t]||t;
if(t==="B"&&/font-weight\s*:\s*(normal|[1-4]00)/i.test(s)){walk(n,to,pre);continue}
if(t==="SPAN"||t==="FONT"){let el=to;const w=g=>{const x=d.createElement(g);el.append(x);el=x};if(/font-weight\s*:\s*(bold|[6-9]00)/i.test(s))w("strong");if(/font-style\s*:\s*italic/i.test(s))w("em");if(/text-decoration[^;]*underline/i.test(s))w("u");if(/text-decoration[^;]*line-through/i.test(s))w("s");walk(n,el,pre);continue}
if(t==="P"&&/MsoListParagraph/i.test(n.className||"")){let ul=to.lastElementChild;if(!ul||ul.tagName!=="UL"||!ul.dataset.w){ul=d.createElement("ul");ul.dataset.w="1";to.append(ul)}const li=d.createElement("li");walk(n,li,pre);ul.append(li);continue}
if(t==="P"&&/^(LI|TD|TH)$/.test(to.tagName)){if(to.childNodes.length)to.append(d.createElement("br"));walk(n,to,pre);continue}
if(!ALLOW.has(t)){if(BLK.has(t)&&![...n.children].some(c=>BLK.has(c.tagName.toUpperCase()))&&!/^(P|LI|TD|TH|H\d|PRE|BLOCKQUOTE)$/.test(to.tagName)){const p=d.createElement("p");walk(n,p,pre);if(!empty(p))to.append(p)}else walk(n,to,pre);continue}
const el=d.createElement(t);
if(t==="A"){const h=(n.getAttribute("href")||"").trim();if(!okHref(h)){walk(n,to,pre);continue}el.href=h;el.rel="noopener noreferrer";el.target="_blank"}
if(t==="IMG"){const u=n.getAttribute("src")||"";if(!appFile(u))continue;el.src=u;el.alt=(n.getAttribute("alt")||"").slice(0,200);to.append(el);continue}
if(t==="UL"&&n.hasAttribute("data-checklist"))el.setAttribute("data-checklist","");
if(t==="LI"&&to.hasAttribute&&to.hasAttribute("data-checklist"))el.dataset.checked=n.dataset.checked==="true"?"true":"false";
if(t==="TD"||t==="TH")for(const a of["colspan","rowspan"]){const v=+n.getAttribute(a);if(v>1&&v<=20)el.setAttribute(a,v)}
walk(n,el,pre||t==="PRE");if(!/^(BR|HR|TD|TH|LI|P)$/.test(t)&&!el.childNodes.length)continue;to.append(el)}};
const clean=h=>{const out=d.createElement("div");walk(new DOMParser().parseFromString(h,"text/html").body,out,false);qa("[data-w]",out).forEach(u=>u.removeAttribute("data-w"));return out.innerHTML},
inline=h=>{const x=d.createElement("div");x.innerHTML=h;return x.children.length===1&&x.firstElementChild.tagName==="P"&&x.childNodes.length===1?x.firstElementChild.innerHTML:h},
plain=t=>t.replace(/\r/g,"").split(/\n\s*\n/).filter(p=>p.trim()).map(p=>"<p>"+p.split("\n").map(N.esc).join("<br>")+"</p>").join("");
/* ---- selection helpers ---- */
const sel=()=>getSelection(),inArea=()=>{const s=sel();return s.rangeCount&&area.contains(s.getRangeAt(0).commonAncestorContainer)},
keep=()=>{if(inArea())last=sel().getRangeAt(0).cloneRange()},
back=()=>{area.focus({preventScroll:true});if(inArea())return;const s=sel();s.removeAllRanges();if(last&&area.contains(last.commonAncestorContainer))s.addRange(last);else{const r=d.createRange();r.selectNodeContents(area);r.collapse(false);s.addRange(r)}},
up=s=>{const x=sel();if(!x.rangeCount)return null;let n=x.anchorNode;n=n&&n.nodeType===3?n.parentNode:n;const el=n&&n.closest?n.closest(s):null;return el&&area.contains(el)?el:null},
exec=(c,v=null)=>{back();d.execCommand(c,false,v)},ins=h=>{if(h)exec("insertHTML",h)};
/* ---- state ---- */
const status=(m,c="")=>{st.textContent=m;st.className="rte-status"+(c?" "+c:"")},
fixEmpty=()=>{if(!area.textContent.trim()&&!area.querySelector("img,hr,table,li"))area.classList.add("is-empty");else area.classList.remove("is-empty")},
paint=()=>{fixEmpty();dirty=area.innerHTML!==saved;if(!busy)status(dirty?"Unsaved changes":f.hasAttribute("data-new")?"Not saved yet":"Saved",dirty?"dirty":"")},
changed=()=>{paint();clearTimeout(timer);timer=setTimeout(()=>{dirty?store.set({html:area.innerHTML,version,t:Date.now()}):store.del()},500)},
norm=()=>{qa("a",area).forEach(a=>{a.rel="noopener noreferrer";a.target="_blank"});qa("ul[data-checklist]>li",area).forEach(li=>{if(li.dataset.checked!=="true")li.dataset.checked="false"});qa("li[data-checked]",area).forEach(li=>{if(!li.parentNode.hasAttribute("data-checklist"))li.removeAttribute("data-checked")})};
/* ---- tables ---- */
const cellAt=()=>up("td,th"),tbl=c=>{const td=cellAt();if(!td)return;const tr=td.parentNode,table=td.closest("table"),i=td.cellIndex,rows=[...table.rows];
if(c==="row+"){const nr=d.createElement("tr");for(let k=0;k<tr.cells.length;k++){const x=d.createElement("td");x.innerHTML="<br>";nr.append(x)}if(tr.parentNode.tagName==="THEAD"){let b=table.tBodies[0];if(!b){b=d.createElement("tbody");table.append(b)}b.prepend(nr)}else tr.after(nr)}
if(c==="col+")rows.forEach(r=>{const x=d.createElement(r.parentNode.tagName==="THEAD"?"th":"td");x.innerHTML="<br>";const ref=r.cells[i];ref?ref.after(x):r.append(x)});
if(c==="row-"){tr.remove();if(!table.rows.length)table.remove()}
if(c==="col-"){rows.forEach(r=>r.cells[i]&&r.cells[i].remove());if(!table.rows[0]||!table.rows[0].cells.length)table.remove()}
qa("thead,tbody",area).forEach(s=>{if(!s.rows.length)s.remove()});changed();state()};
/* ---- commands ---- */
const unwrap=el=>{const p=d.createElement("p");if(el.tagName==="PRE"){p.innerHTML=N.esc(el.textContent).replace(/\n/g,"<br>")||"<br>";el.replaceWith(p);return}if([...el.children].some(c=>BLK.has(c.tagName))){el.replaceWith(...el.childNodes);return}p.append(...el.childNodes);el.replaceWith(p)};
const link=()=>{keep();const a=up("a"),u0=a?a.getAttribute("href"):"https://";let u=prompt("Link address (https://, http:// or mailto:). Leave empty to remove the link.",u0);if(u===null){back();return}u=u.trim();
if(!u){if(a){back();const r=d.createRange();r.selectNodeContents(a);sel().removeAllRanges();sel().addRange(r);d.execCommand("unlink")}return}
if(!/^[a-z][a-z0-9+.-]*:/i.test(u))u=/^[^@\s]+@[^@\s]+\.[a-z]{2,}$/i.test(u)?"mailto:"+u:"https://"+u;if(!okHref(u)){N.toast("Use an http, https or mailto link.","error");back();return}
back();if(a){a.href=u}else if(sel().isCollapsed)ins('<a href="'+N.esc(u)+'">'+N.esc(u.replace(/^mailto:/,""))+"</a>&nbsp;");else exec("createLink",u);norm();changed()};
/* tables and rules go after the list the caret is in, not inside a list item */
const outList=()=>{back();let n=sel().anchorNode,l=null;while(n&&n!==area){if(n.nodeType===1&&/^(UL|OL)$/.test(n.tagName))l=n;n=n.parentNode}if(l){while(l.parentNode!==area)l=l.parentNode;const p=d.createElement("p");p.innerHTML="<br>";l.after(p);const r=d.createRange();r.setStart(p,0);r.collapse(true);sel().removeAllRanges();sel().addRange(r)}};
const run=c=>{if(c.startsWith("block:")){const v=c.slice(6);if(up("li")&&!up("td,th"))exec(up("ol")&&!up("ul")?"insertOrderedList":"insertUnorderedList");const b=up("h2,h3,h4,p,pre,blockquote");if(b&&b.tagName==="PRE")unwrap(b);if(b&&b.tagName==="BLOCKQUOTE"&&v!=="p")unwrap(b);exec("formatBlock","<"+(b&&b.tagName.toLowerCase()===v?"p":v)+">")}
else if(["bold","italic","underline","strikeThrough","undo","redo"].includes(c))exec(c);
else if(c==="ul"||c==="ol"||c==="check"){const h=up("h2,h3,h4,pre");if(h&&!up("li"))exec("formatBlock","<p>")}if(c==="ul"||c==="ol"){const cl=up("ul[data-checklist]");if(c==="ul"&&cl){cl.removeAttribute("data-checklist");qa("li",cl).forEach(li=>li.removeAttribute("data-checked"))}else exec(c==="ul"?"insertUnorderedList":"insertOrderedList")}
if(c==="check"){const cl=up("ul[data-checklist]");if(cl){qa("li",cl).forEach(li=>li.removeAttribute("data-checked"));cl.removeAttribute("data-checklist");exec("insertUnorderedList")}else{if(!up("ul")||up("ol"))exec("insertUnorderedList");const u=up("ul");if(u){u.setAttribute("data-checklist","");norm()}}}
else if(c==="quote"){const b=up("blockquote");b?unwrap(b):exec("formatBlock","<blockquote>")}
else if(c==="code"){const b=up("pre");b?unwrap(b):exec("formatBlock","<pre>")}
else if(c==="link")link();else if(c==="image"){keep();fileIn.click()}else if(c==="hr"){outList();exec("insertHorizontalRule")}
else if(c==="table"){outList();ins('<table><thead><tr><th><br></th><th><br></th></tr></thead><tbody><tr><td><br></td><td><br></td></tr></tbody></table><p><br></p>')}
else if(/^(row|col)[+-]$/.test(c))tbl(c);
else if(c==="clear"){exec("removeFormat");const b=up("h2,h3,h4,pre,blockquote");if(b)b.tagName==="PRE"||b.tagName==="BLOCKQUOTE"?unwrap(b):exec("formatBlock","<p>")}
changed();state()};
/* toolbar state (aria-pressed) */
let raf=0;const state=()=>{cancelAnimationFrame(raf);raf=requestAnimationFrame(()=>{if(!inArea())return;keep();const blk=up("h2,h3,h4,pre,blockquote,p,li"),tag=blk?blk.tagName:"",qs=c=>{try{return d.queryCommandState(c)}catch(e){return false}},cl=up("ul[data-checklist]");
const on={"block:p":tag==="P"&&!up("blockquote"),"block:h2":tag==="H2","block:h3":tag==="H3",bold:qs("bold"),italic:qs("italic"),underline:qs("underline"),strikeThrough:qs("strikeThrough"),ul:!!up("ul")&&!cl,ol:!!up("ol"),check:!!cl,quote:!!up("blockquote"),code:!!up("pre")};
qa("[aria-pressed]",bar).forEach(b=>b.setAttribute("aria-pressed",on[b.dataset.cmd]?"true":"false"));tblBar.hidden=!cellAt()})};
/* ---- uploads (images inline, other files as links) ---- */
const docs=d.querySelector("[data-brief-docs]"),addDoc=j=>{if(!docs)return;const x=(j.name.split(".").pop()||"file").slice(0,4).toUpperCase(),el=d.createElement("div");el.className="bdoc";el.innerHTML='<span class="file-ext'+(j.image?" x-img":"")+'">'+N.esc(x)+'</span><div class="grow"><a class="b truncate db" target="_blank" rel="noopener" href="'+N.esc(j.url)+'">'+N.esc(j.name)+'</a><div class="xs muted">Just uploaded</div></div>';docs.prepend(el);const n=d.querySelector("[data-brief-doc-n]");if(n)n.textContent=(+n.textContent||0)+1;const em=d.querySelector("[data-brief-docs-empty]");if(em)em.remove()};
let pending=0;const upload=file=>{const max=+f.dataset.max||0,name=file.name&&file.name!=="image.png"?file.name:"pasted-"+new Date().toISOString().slice(0,19).replace(/[^\d]/g,"")+"."+((file.type.split("/")[1]||"png").replace("jpeg","jpg"));
if(max&&file.size>max){N.toast(name+" is larger than "+Math.round(max/1048576)+" MB.","error");return}keep();const at=last&&last.cloneRange(),fd=new FormData();fd.append("file",file,name);fd.append("category","brief");pending++;status("Uploading "+name+"…","busy");
N.req(f.dataset.upload,{body:fd}).then(j=>{if(at&&area.contains(at.commonAncestorContainer)){area.focus({preventScroll:true});const s=sel();s.removeAllRanges();s.addRange(at)}ins(j.image?'<img src="'+N.esc(j.url)+'" alt="'+N.esc(j.name)+'">':'<a href="'+N.esc(j.download)+'">'+N.esc(j.name)+"</a>&nbsp;");norm();addDoc(j)}).catch(e=>N.toast(e.message||"Upload failed.","error")).finally(()=>{pending--;changed()})};
fileIn.addEventListener("change",()=>{[...fileIn.files].forEach(upload);fileIn.value=""});
/* ---- events ---- */
bar.addEventListener("mousedown",e=>{if(e.target.closest(".rte-b"))e.preventDefault()});
bar.addEventListener("click",e=>{const b=e.target.closest(".rte-b");if(b)run(b.dataset.cmd)});
bar.addEventListener("keydown",e=>{const l=btns(),i=l.indexOf(d.activeElement);if(i<0)return;let j=-1;if(e.key==="ArrowRight")j=(i+1)%l.length;else if(e.key==="ArrowLeft")j=(i-1+l.length)%l.length;else if(e.key==="Home")j=0;else if(e.key==="End")j=l.length-1;if(j<0)return;e.preventDefault();l.forEach(b=>b.tabIndex=-1);l[j].tabIndex=0;l[j].focus()});
const first=btns()[0];if(first)first.tabIndex=0;
d.addEventListener("selectionchange",state);
area.addEventListener("input",e=>{if(e.inputType==="insertParagraph"){const li=up("ul[data-checklist]>li");if(li)li.dataset.checked="false"}if(!area.firstChild||area.innerHTML==="<br>"){area.innerHTML="<p><br></p>";back()}changed()});
area.addEventListener("keydown",e=>{const m=e.ctrlKey||e.metaKey,k=e.key.toLowerCase();
if(m&&!e.altKey){const c={b:"bold",i:"italic",u:"underline",k:"link",z:e.shiftKey?"redo":"undo",y:"redo"}[k];if(c){e.preventDefault();run(c);return}if(k==="s"){e.preventDefault();save(false);return}if(k==="enter"){const li=up("ul[data-checklist]>li");if(li){e.preventDefault();li.dataset.checked=li.dataset.checked==="true"?"false":"true";changed()}return}}
if(e.key==="Tab"&&!m&&!e.altKey){const td=cellAt();if(td){e.preventDefault();const cs=[...td.closest("table").querySelectorAll("th,td")],i=cs.indexOf(td)+(e.shiftKey?-1:1);if(i>=cs.length){tbl("row+")}const n=[...td.closest("table").querySelectorAll("th,td")][Math.max(0,i)];if(n){const r=d.createRange();r.selectNodeContents(n);r.collapse(false);sel().removeAllRanges();sel().addRange(r)}return}
if(up("li")){e.preventDefault();exec(e.shiftKey?"outdent":"indent");changed()}}});
area.addEventListener("click",e=>{const li=e.target.closest&&e.target.closest("ul[data-checklist]>li");if(!li||!area.contains(li))return;const r=li.getBoundingClientRect();if(e.clientX-r.left<=26&&e.clientY-r.top<=30){e.preventDefault();li.dataset.checked=li.dataset.checked==="true"?"false":"true";changed()}});
area.addEventListener("paste",e=>{const dt=e.clipboardData;if(!dt)return;const h=dt.getData("text/html"),t=dt.getData("text/plain"),imgs=[...dt.files].filter(x=>x.type.startsWith("image/"));e.preventDefault();
if(imgs.length){const c=h?clean(h):"",tmp=d.createElement("div");tmp.innerHTML=c;if(!c||empty(tmp)){imgs.forEach(upload);return}}
if(up("pre")||!h&&!/\n/.test(t)){exec("insertText",t);changed();return}ins(inline(h?clean(h):plain(t)));norm();changed()});
area.addEventListener("dragstart",()=>{inner=true});area.addEventListener("dragend",()=>{inner=false});
area.addEventListener("dragover",e=>{if(!inner){e.preventDefault();area.classList.add("drop")}});["dragleave","drop"].forEach(v=>area.addEventListener(v,()=>area.classList.remove("drop")));
area.addEventListener("drop",e=>{if(inner)return;const dt=e.dataTransfer;e.preventDefault();let r=null;if(d.caretRangeFromPoint)r=d.caretRangeFromPoint(e.clientX,e.clientY);else if(d.caretPositionFromPoint){const p=d.caretPositionFromPoint(e.clientX,e.clientY);if(p){r=d.createRange();r.setStart(p.offsetNode,p.offset)}}
if(r&&area.contains(r.startContainer)){area.focus({preventScroll:true});sel().removeAllRanges();sel().addRange(r);keep()}if(dt.files.length){[...dt.files].forEach(upload);return}const h=dt.getData("text/html"),t=dt.getData("text/plain");ins(h?clean(h):plain(t));norm();changed()});
/* ---- save ---- */
const save=force=>{if(busy)return;if(pending){N.toast("Wait for the upload to finish.","error");return}busy=true;norm();const sent=area.innerHTML;status("Saving…","busy");
N.req(f.dataset.save,{body:{body:sent,version,force:force?1:0}}).then(j=>{busy=false;version=String(j.version||"");f.dataset.version=version;f.removeAttribute("data-new");
let base=sent;if(area.innerHTML===sent){const tmp=d.createElement("div");tmp.innerHTML=j.html;if(tmp.innerHTML!==sent)area.innerHTML=j.html||"<p><br></p>";base=area.innerHTML}saved=base;store.del();changed();if(!dirty)status(j.changed?"Saved":"Saved, no changes","ok");
const m=d.querySelector("[data-rte-meta]");if(m&&j.at)m.textContent="Last edited"+(j.by?" by "+j.by:"")+", "+j.ago}).catch(err=>{busy=false;if(err.status===409&&confirm(err.message+"\n\nSave your version anyway? Their version stays in History."))return save(true);status("Not saved","error");N.toast(err.message||"Could not save.","error")})};
f.addEventListener("submit",e=>{e.preventDefault();save(false)});
q("[data-rte-close]").addEventListener("click",e=>{if(dirty&&!confirm("Discard your unsaved changes?")){e.preventDefault();return}store.del();leaving=true});
addEventListener("beforeunload",e=>{if((dirty||pending)&&!leaving){e.preventDefault();e.returnValue=""}});
/* ---- init: baseline, local draft ---- */
if(!area.innerHTML.trim())area.innerHTML="<p><br></p>";norm();saved=area.innerHTML;paint();
const dr=store.get();if(dr&&dr.html&&dr.html!==area.innerHTML&&dr.html!==saved){q("[data-rte-draft-time]").textContent=new Date(dr.t||Date.now()).toLocaleString();draftBox.hidden=false;
q("[data-rte-draft-use]").addEventListener("click",()=>{area.innerHTML=clean(dr.html)||"<p><br></p>";if(dr.version&&dr.version!==version)N.toast("The brief was saved by someone else after this draft. Check History before saving.","error");draftBox.hidden=true;norm();changed();area.focus()});
q("[data-rte-draft-drop]").addEventListener("click",()=>{store.del();draftBox.hidden=true})}
area.focus({preventScroll:true})})();
