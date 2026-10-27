// Settings app: assistant and user names. The saved values arrive in
// #settings-names, a JSON data block the handler fills.
var NAMES=JSON.parse(document.getElementById('settings-names').textContent);
var A0=NAMES.assistant, U0=NAMES.user;
// The double-submit CSRF token (the readable XSRF-TOKEN cookie), echoed as
// X-CSRF-Token: without it a signed-in POST was answered 403.
function csrfToken(){var m=document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);return m?decodeURIComponent(m[1]):'';}
var an=document.getElementById('assistant'), un=document.getElementById('user'), save=document.getElementById('save'), hint=document.getElementById('hint');
function setHint(msg, cls){ hint.textContent=msg||''; hint.className='hint'+(cls?' '+cls:''); }
async function persist(){
  var a=(an.value||'').trim(), u=(un.value||'').trim();
  if(!a){ setHint('An assistant name is required.', 'err'); an.focus(); return; }
  var body={};
  if(a!==A0) body.assistantName=a;
  if(u && u!==U0) body.userName=u;
  if(Object.keys(body).length===0){ setHint('Nothing changed.', ''); return; }
  save.disabled=true; setHint('Saving…', '');
  try{
    var r=await fetch('/os/preferences',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-Token':csrfToken()},body:JSON.stringify(body)});
    var d=await r.json();
    if(d.ok){
      A0=d.assistant_name; U0=d.user_name; an.value=d.assistant_name; un.value=d.user_name;
      setHint('Saved.', 'ok');
      try{ window.parent.postMessage({type:'os:prefs-changed', assistantName:d.assistant_name, userName:d.user_name}, '*'); }catch(e){}
    } else {
      setHint(d.error||'Could not save.', 'err');
    }
  }catch(e){ setHint(e.message||'Could not save.', 'err'); }
  save.disabled=false;
}
save.addEventListener('click', persist);
[an, un].forEach(function(el){ el.addEventListener('keydown', function(e){ if(e.key==='Enter'){ e.preventDefault(); persist(); } }); });
