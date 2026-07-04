<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Os\Application\Payload\Request\SettingsAppPayload;
use Semitexa\Os\Application\Service\OsPreferences;

/**
 * Renders the Settings dialog body — the OS personalisation form (v0: the
 * assistant's name and the user's own name). Standalone HTML embedded as an
 * iframe by the Focus zone (web mode) or opened as its own native window (OS
 * mode). On save it POSTs to /os/preferences — the source of truth — and, in
 * web mode, postMessages the parent shell for an instant update. In OS mode the
 * shell re-syncs from /os/preferences when it regains focus (it is a separate
 * process, so the postMessage cannot reach it).
 */
#[AsPayloadHandler(payload: SettingsAppPayload::class, resource: ResourceResponse::class)]
final class SettingsAppHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected OsPreferences $prefs;

    public function handle(SettingsAppPayload $payload, ResourceResponse $resource): ResourceResponse
    {
        $prefs = $this->prefs->all();
        $enc = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $js = static fn (string $v): string => (string) json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $html = <<<'HTML'
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Settings</title>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<style>
  *{box-sizing:border-box} html,body{margin:0;height:100%}
  body{font-family:'IBM Plex Sans',system-ui,sans-serif;background:var(--bg);color:var(--text);padding:26px 26px 22px;font-size:14px}
  h2{margin:0 0 2px;font-size:16px;font-weight:600;color:var(--strong)}
  .sub{color:var(--mute);font-size:12px;margin-bottom:22px}
  .field{margin-bottom:18px}
  label{display:block;font-size:12px;color:var(--text3);margin-bottom:7px}
  input{width:100%;background:var(--well);border:1px solid rgba(var(--line-rgb),.22);border-radius:9px;padding:11px 13px;color:var(--strong);font:inherit;outline:none}
  input:focus{border-color:var(--accent)}
  .actions{display:flex;align-items:center;gap:12px;margin-top:4px}
  button{background:var(--accent);border:none;border-radius:9px;padding:11px 20px;color:var(--btn-text);font:inherit;font-weight:600;cursor:pointer}
  button:disabled{opacity:.5;cursor:default}
  .hint{font-size:12px;min-height:16px}
  .ok{color:var(--ok)} .err{color:var(--err)}
  .note{margin-top:20px;font-size:11px;color:var(--faint);line-height:1.5}
  :root{color-scheme:dark;--bg:#0c1020;--well:#0a0d18;--text:#cdd9ee;--strong:#eaf2ff;--mute:#6f7d99;--text3:#a8b4cc;--faint:#5b6782;
    --accent:#37b7ff;--btn-text:#04121f;--line-rgb:148,163,184;--ok:#5eead4;--err:#ff9aab}
  :root[data-mode=light]{color-scheme:light;--bg:#f4f7fb;--well:#ffffff;--text:#243447;--strong:#16222e;--mute:#5b6c82;--text3:#3b4c61;--faint:#8593a8;
    --accent:#1e7fb8;--btn-text:#ffffff;--line-rgb:100,116,139;--ok:#0e8a72;--err:#b13a52}
</style><script>
/* Follow the OS theme: pref lives server-side; 'auto' resolves with the shell's
   exact rule (prefers-color-scheme, else dark 19:00-07:00). Self-resolution
   works in web iframes AND OS-mode native windows. */
(function(){
  function applyMode(mode){
    var eff=(mode==='light'||mode==='dark')?mode:(function(){
      try{ if(window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches) return 'dark'; }catch(e){}
      var h=new Date().getHours(); return (h>=19||h<7)?'dark':'light';
    })();
    var el=document.documentElement;
    if(el.getAttribute('data-mode')!==eff){ el.setAttribute('data-mode',eff); el.style.colorScheme=eff; }
  }
  function syncMode(){
    fetch('/os/preferences',{headers:{'Accept':'application/json'}})
      .then(function(r){return r.json();}).then(function(d){ applyMode((d&&d.theme_mode)||'auto'); })
      .catch(function(){});
  }
  syncMode(); window.addEventListener('focus', syncMode); setInterval(syncMode, 15000);
})();
</script>
</head>
<body>
  <h2>Settings</h2>
  <div class="sub">Personalise your OS.</div>
  <div class="field">
    <label for="assistant">Assistant name</label>
    <input id="assistant" value="%ANAME_ATTR%" maxlength="24" autocomplete="off" spellcheck="false" placeholder="Semi">
  </div>
  <div class="field">
    <label for="user">Your name</label>
    <input id="user" value="%UNAME_ATTR%" maxlength="24" autocomplete="off" spellcheck="false" placeholder="What should I call you?">
  </div>
  <div class="actions"><button id="save">Save</button><span class="hint" id="hint"></span></div>
  <div class="note">You can also just say it: “call yourself Jarvis”, or “my name is Alex”.</div>
<script>
  var A0=%ANAME_JS%, U0=%UNAME_JS%;
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
      var r=await fetch('/os/preferences',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify(body)});
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
</script>
</body></html>
HTML;

        $html = str_replace(
            ['%ANAME_ATTR%', '%UNAME_ATTR%', '%ANAME_JS%', '%UNAME_JS%'],
            [$enc($prefs['assistant_name']), $enc($prefs['user_name']), $js($prefs['assistant_name']), $js($prefs['user_name'])],
            $html,
        );

        return $resource
            ->setContent($html)
            ->setHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
