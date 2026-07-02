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
  body{font-family:'IBM Plex Sans',system-ui,sans-serif;background:#0c1020;color:#cdd9ee;padding:26px 26px 22px;font-size:14px}
  h2{margin:0 0 2px;font-size:16px;font-weight:600;color:#eaf2ff}
  .sub{color:#6f7d99;font-size:12px;margin-bottom:22px}
  .field{margin-bottom:18px}
  label{display:block;font-size:12px;color:#a8b4cc;margin-bottom:7px}
  input{width:100%;background:#0a0d18;border:1px solid rgba(148,163,184,.22);border-radius:9px;padding:11px 13px;color:#eaf2ff;font:inherit;outline:none}
  input:focus{border-color:#37b7ff}
  .actions{display:flex;align-items:center;gap:12px;margin-top:4px}
  button{background:#37b7ff;border:none;border-radius:9px;padding:11px 20px;color:#04121f;font:inherit;font-weight:600;cursor:pointer}
  button:disabled{opacity:.5;cursor:default}
  .hint{font-size:12px;min-height:16px}
  .ok{color:#5eead4} .err{color:#ff9aab}
  .note{margin-top:20px;font-size:11px;color:#5b6782;line-height:1.5}
</style></head>
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
