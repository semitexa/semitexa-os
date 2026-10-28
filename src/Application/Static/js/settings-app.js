// Settings app: assistant and user names, and the account's own password.
// The saved names arrive in #settings-names, a JSON data block the handler
// fills; the password form is there only for a signed-in account.
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

// Password: POST /os/password. A refusal names the field (422 {errors}), the
// limit answers 429 with Retry-After, a lapsed session 401.
var pform=document.getElementById('password-form');
if(pform){
  var phint=document.getElementById('password-hint'), pbtn=document.getElementById('change-password');
  var setPHint=function(msg, cls){ phint.textContent=msg||''; phint.className='hint'+(cls?' '+cls:''); };
  pform.addEventListener('submit', async function(e){
    e.preventDefault();
    var body={
      currentPassword:document.getElementById('current-password').value,
      newPassword:document.getElementById('new-password').value,
      repeatPassword:document.getElementById('repeat-password').value
    };
    pbtn.disabled=true; setPHint('Changing…', '');
    try{
      var r=await fetch('/os/password',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-Token':csrfToken()},body:JSON.stringify(body)});
      var d={}; try{ d=await r.json(); }catch(_){}
      if(r.ok && d.ok){
        pform.reset();
        setPHint('Password changed.', 'ok');
        // The console was closed to an issued password: open it now.
        if(pform.getAttribute('data-must-change')==='1'){ window.top.location.href='/os'; }
      } else if(r.status===429){
        var wait=parseInt(r.headers.get('Retry-After')||'0', 10);
        setPHint('Too many attempts. Try again in '+Math.max(1, Math.ceil(wait/60))+' min.', 'err');
      } else if(r.status===401){
        window.top.location.reload();
      } else {
        var errs=d.errors||{}, first=null;
        for(var k in errs){ if(errs[k] && errs[k].length){ first=errs[k][0]; break; } }
        setPHint(first||d.error||d.message||'Could not change the password.', 'err');
      }
    }catch(err){ setPHint(err.message||'Could not change the password.', 'err'); }
    pbtn.disabled=false;
  });
}
