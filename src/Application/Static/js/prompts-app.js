// Prompts app for the OS dialog: list, read and override prompt templates.
// Served as a static asset; the prompt list arrives in #prompts-data.
(function(){
  var DATA = JSON.parse(document.getElementById('prompts-data').textContent).prompts || [];
  var byId = {}; DATA.forEach(function(p){ byId[p.id] = p; });
  // The double-submit CSRF token (the readable XSRF-TOKEN cookie), echoed as
  // X-CSRF-Token: without it a signed-in POST was answered 403.
  function csrfToken(){var m=document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);return m?decodeURIComponent(m[1]):'';}
  var sel = null;
  var listEl = document.getElementById('list');
  var mainEl = document.getElementById('main');
  var searchEl = document.getElementById('search');

  function esc(s){ return (s==null?'':String(s)).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];}); }
  function effective(p){ return p.override != null ? p.override : (p.base != null ? p.base : ''); }

  function renderList(){
    var q = searchEl.value.trim().toLowerCase();
    listEl.innerHTML = '';
    DATA.forEach(function(p){
      if(q && p.id.toLowerCase().indexOf(q) < 0 && (p.channel||'').toLowerCase().indexOf(q) < 0) return;
      var el = document.createElement('div');
      el.className = 'item' + (p.override!=null?' ov':'') + (sel===p.id?' sel':'');
      el.innerHTML = '<span class="dot" title="overridden"></span><span class="id">'+esc(p.id)+'</span><span class="ch">'+esc(p.channel)+'</span>';
      el.onclick = function(){ select(p.id); };
      listEl.appendChild(el);
    });
  }

  function select(id){
    sel = id; renderList();
    var p = byId[id];
    var vars = (p.variables||[]).map(function(v){ return '<span class="chip v">{{ '+esc(v)+' }}</span>'; }).join('');
    var baseBlock = p.base!=null && p.override!=null
      ? '<details><summary>Catalog default</summary><pre class="base">'+esc(p.base)+'</pre></details>' : '';
    mainEl.innerHTML =
      '<div class="head">'
      + '<div style="display:flex;align-items:center;gap:10px"><span class="pid">'+esc(p.id)+'</span>'
      + '<span class="badge '+(p.override!=null?'ov':'base')+'" id="badge">'+(p.override!=null?'overridden':'catalog default')+'</span></div>'
      + (p.description?'<div class="desc">'+esc(p.description)+'</div>':'')
      + '<div class="meta"><span class="chip">'+esc(p.channel)+'</span>'+vars+'</div>'
      + '</div>'
      + '<div class="edit"><div class="lbl">System prompt'+(p.base==null?' (override only)':'')+'</div>'
      + '<textarea id="ta" spellcheck="false"></textarea>' + baseBlock
      + '<details id="histbox"><summary>Version history</summary><div id="history" class="histlist"><div class="histmute">Loading…</div></div></details></div>'
      + '<div class="foot">'
      + '<button class="primary" id="save" disabled>Save override</button>'
      + '<button class="ghost" id="reset"'+(p.override==null?' disabled':'')+'>Reset to default</button>'
      + '<span class="status" id="status"></span></div>';
    var ta = document.getElementById('ta');
    ta.value = effective(p);
    ta.oninput = function(){ document.getElementById('save').disabled = (ta.value === effective(p)); };
    document.getElementById('save').onclick = function(){ save(p, ta.value); };
    document.getElementById('reset').onclick = function(){ reset(p); };
    loadHistory(p.id);
  }

  async function loadHistory(id){
    var box = document.getElementById('history'); if(!box) return;
    try{
      var r = await fetch('/os/prompts/history?id='+encodeURIComponent(id), {headers:{'Accept':'application/json'}});
      var d = await r.json();
      if(sel !== id) return;
      var vs = d.versions || [];
      if(!vs.length){ box.innerHTML = '<div class="histmute">No saved versions yet.</div>'; return; }
      box.innerHTML = '';
      vs.forEach(function(v){
        var line = (v.system||'').split('\n')[0];
        var row = document.createElement('div');
        row.className = 'histrow';
        row.innerHTML = '<span class="hv">v'+v.version+'</span><span class="ht">'+esc((v.created_at||'').replace('T',' ').slice(0,19))+'</span><span class="hs">'+esc(line)+'</span>';
        var btn = document.createElement('button'); btn.className='ghost'; btn.textContent='Restore';
        btn.onclick = function(){ restore(id, v.version); };
        row.appendChild(btn); box.appendChild(row);
      });
    }catch(e){ box.innerHTML = '<div class="histmute">History unavailable.</div>'; }
  }

  async function restore(id, version){
    try{
      var d = await post({id:id, action:'restore', version:String(version)});
      if(d.ok){
        // The restore answers with the override now in effect — not the newest
        // history entry, which lags behind when the version log fails.
        if(typeof d.system === 'string') byId[id].override = d.system;
        select(id); flash('Restored v'+version); notify(id);
      } else flash(d.error||'Restore failed', true);
    }catch(e){ flash('Network error', true); }
  }

  function flash(msg, isErr){
    var s = document.getElementById('status'); if(!s) return;
    s.textContent = msg; s.className = 'status show' + (isErr?' err':'');
    setTimeout(function(){ s.className = 'status' + (isErr?' err':''); }, 2200);
  }

  async function post(body){
    var r = await fetch('/os/prompts/save', {method:'POST',
      headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-Token':csrfToken()}, body: JSON.stringify(body)});
    return r.json();
  }

  async function save(p, system){
    try{
      var d = await post({id:p.id, action:'save', system:system});
      if(d.ok){ p.override = system; select(p.id); flash('Saved · override active'); notify(p.id); }
      else flash(d.error||'Save failed', true);
    }catch(e){ flash('Network error', true); }
  }

  async function reset(p){
    try{
      var d = await post({id:p.id, action:'reset'});
      if(d.ok){ p.override = null; select(p.id); flash('Reset to catalog default'); notify(p.id); }
      else flash(d.error||'Reset failed', true);
    }catch(e){ flash('Network error', true); }
  }

  function notify(id){ try{ window.parent.postMessage({type:'os:prompt-override-changed', id:id}, window.location.origin); }catch(e){} }

  searchEl.oninput = renderList;
  renderList();

  // Follow the OS theme (auto: prefers-color-scheme, else dark 19:00-07:00).
  function applyMode(mode){
    var eff, m = window.matchMedia;
    if(mode==='light'||mode==='dark') eff = mode;
    else if(m && m('(prefers-color-scheme: dark)').matches) eff='dark';
    else if(m && m('(prefers-color-scheme: light)').matches) eff='light';
    else { var h=new Date().getHours(); eff=(h>=19||h<7)?'dark':'light'; }
    document.documentElement.setAttribute('data-mode', eff);
  }
  async function syncTheme(){
    try{ var r=await fetch('/os/preferences',{headers:{'Accept':'application/json'}}); var d=await r.json(); applyMode(d.theme_mode||'auto'); }
    catch(e){ applyMode('auto'); }
  }
  syncTheme(); setInterval(syncTheme, 4000);
})();
