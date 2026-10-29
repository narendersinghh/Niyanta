(() => {
  'use strict';
  const t = document.getElementById('t');
  let timer = null;
  window.toastBridge.onToast(d => {
    document.getElementById('title').textContent = d.title || 'Screenshot taken';
    document.getElementById('body').textContent = d.body || '';
    const img = document.getElementById('img');
    if (d.thumb && /^data:image\//.test(d.thumb)) img.src = d.thumb; else img.removeAttribute('src');
    t.classList.add('on');
    clearTimeout(timer);
    timer = setTimeout(() => t.classList.remove('on'), 2300);
  });
})();
