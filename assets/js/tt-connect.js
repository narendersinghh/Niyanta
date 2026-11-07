/* Niyanta Tracker connect page: open the deep link (niyanta-tracker://connect?...) once; the link stays for a manual click. */
(function(){var a=document.querySelector('[data-deeplink]');if(!a||a.dataset.opened)return;a.dataset.opened='1';setTimeout(function(){try{window.location.href=a.getAttribute('href')}catch(e){}},250)})();
