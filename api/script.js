document.addEventListener('DOMContentLoaded',()=>{
  const params=new URLSearchParams(location.search);
  if(params.get('ok')){
    console.log('Registration successful');
  }
  // Registration tabs on homepage
  const tabWholesale = document.getElementById('tab-wholesale');
  const tabAgent = document.getElementById('tab-agent');
  const panelWholesale = document.getElementById('panel-wholesale');
  const panelAgent = document.getElementById('panel-agent');
  if (tabWholesale && tabAgent && panelWholesale && panelAgent) {
    function selectTab(which){
      const isWholesale = which === 'wholesale';
      tabWholesale.setAttribute('aria-selected', isWholesale ? 'true' : 'false');
      tabAgent.setAttribute('aria-selected', isWholesale ? 'false' : 'true');
      panelWholesale.hidden = !isWholesale;
      panelAgent.hidden = isWholesale;
      if (isWholesale) {
        tabWholesale.classList.remove('secondary');
        tabWholesale.style.background = '#059669';
        tabWholesale.style.color = '#fff';
        tabAgent.classList.add('secondary');
        tabAgent.style.background = '';
        tabAgent.style.color = '';
      } else {
        tabAgent.classList.remove('secondary');
        tabAgent.style.background = '#059669';
        tabAgent.style.color = '#fff';
        tabWholesale.classList.add('secondary');
        tabWholesale.style.background = '';
        tabWholesale.style.color = '';
      }
    }
    tabWholesale.addEventListener('click', () => selectTab('wholesale'));
    tabAgent.addEventListener('click', () => selectTab('agent'));
  }
  const prefersReduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if(prefersReduce) return;
  const els = Array.from(document.querySelectorAll('[data-parallax]'));
  if(!els.length) return;
  let ticking=false;
  const onScroll=()=>{
    if(ticking) return; ticking=true;
    requestAnimationFrame(()=>{
      const y = window.scrollY || 0;
      for(const el of els){
        const speed = parseFloat(el.getAttribute('data-speed')||'0.2');
        el.style.transform = `translate3d(0, ${Math.round(y*speed)}px, 0)`;
      }
      ticking=false;
    });
  };
  window.addEventListener('scroll', onScroll, {passive:true});
  onScroll();
});

// Accessible nav toggle
document.addEventListener('click', (e)=>{
  const btn = e.target.closest('.nav-toggle');
  if(!btn) return;
  const nav = document.getElementById('primary-nav');
  const expanded = btn.getAttribute('aria-expanded') === 'true';
  btn.setAttribute('aria-expanded', expanded ? 'false' : 'true');
  if(nav){ nav.classList.toggle('open'); }
});