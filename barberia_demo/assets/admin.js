(function(){
  function qs(sel, root){ return (root||document).querySelector(sel); }
  function qsa(sel, root){ return Array.prototype.slice.call((root||document).querySelectorAll(sel)); }

  // Mobile sheet menu
  function openSheet(){
    var sheet = qs('#mobileMenu');
    if(!sheet) return;
    sheet.setAttribute('aria-hidden','false');
    document.body.classList.add('sheet-open');
  }
  function closeSheet(){
    var sheet = qs('#mobileMenu');
    if(!sheet) return;
    sheet.setAttribute('aria-hidden','true');
    document.body.classList.remove('sheet-open');
  }
  document.addEventListener('click', function(e){
    var t = e.target;
    // Click may land on the icon/label inside the button; use closest().
    if(t && t.closest && t.closest('#btnMore')){ openSheet(); return; }
    if(t && (t.getAttribute('data-close') === '1')){ closeSheet(); return; }
  });

  // Tap tooltips
  var currentPop = null;
  function closeTip(){
    if(currentPop){ currentPop.remove(); currentPop = null; }
  }
  function openTip(btn){
    closeTip();
    var msg = btn.getAttribute('data-tip') || '';
    if(!msg) return;
    var pop = document.createElement('div');
    pop.className = 'tip-pop';
    pop.innerHTML = '<div>'+escapeHtml(msg)+'</div><a href="#" class="tip-close">Cerrar</a>';
    document.body.appendChild(pop);

    // position near button
    var r = btn.getBoundingClientRect();
    var top = Math.min(window.innerHeight - pop.offsetHeight - 12, r.bottom + 10);
    var left = Math.min(window.innerWidth - pop.offsetWidth - 12, Math.max(12, r.left));
    pop.style.top = top + 'px';
    pop.style.left = left + 'px';

    pop.addEventListener('click', function(ev){
      if(ev.target && ev.target.classList.contains('tip-close')){
        ev.preventDefault();
        closeTip();
      }
    });
    currentPop = pop;
  }

  function escapeHtml(str){
    return String(str)
      .replace(/&/g,'&amp;')
      .replace(/</g,'&lt;')
      .replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;')
      .replace(/'/g,'&#039;');
  }

  document.addEventListener('click', function(e){
    var t = e.target;
    if(t && t.classList && t.classList.contains('tip-btn')){
      e.preventDefault();
      openTip(t);
      return;
    }
    // click outside tooltip closes it
    if(currentPop && !currentPop.contains(t)){
      closeTip();
    }
  });

  window.addEventListener('scroll', closeTip, true);
  window.addEventListener('resize', function(){ closeTip(); closeSheet(); });

})();
