<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Os\Application\Payload\Request\NotesAppPayload;

/**
 * Renders the Notes dialog body — a self-contained, interactive notes editor
 * (textarea persisted to localStorage) hosted inside the Notes dialog surface.
 * Standalone HTML because the Focus zone embeds it as an iframe.
 */
#[AsPayloadHandler(payload: NotesAppPayload::class, resource: ResourceResponse::class)]
final class NotesAppHandler implements TypedHandlerInterface
{
    public function handle(NotesAppPayload $payload, ResourceResponse $resource): ResourceResponse
    {
        $html = <<<'HTML'
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Notes</title>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400&display=swap" rel="stylesheet">
<style>
  *{box-sizing:border-box} html,body{margin:0;height:100%}
  body{font-family:'IBM Plex Sans',system-ui,sans-serif;background:var(--bg);color:var(--text);display:flex;flex-direction:column}
  .bar{display:flex;align-items:center;gap:10px;padding:10px 14px;border-bottom:1px solid rgba(var(--line-rgb),.18);font-size:12px;color:var(--mute)}
  .bar b{color:var(--strong);font-weight:600}
  .saved{margin-left:auto;font-family:'IBM Plex Mono',monospace;font-size:11px;color:var(--ok);opacity:0;transition:opacity .3s}
  .saved.show{opacity:1}
  textarea{flex:1;width:100%;border:none;outline:none;resize:none;background:transparent;color:var(--strong);
    font-family:'IBM Plex Sans',sans-serif;font-size:15px;line-height:1.7;padding:16px 18px}
  textarea::placeholder{color:var(--dim)}
  :root{color-scheme:dark;--bg:#1e1e2e;--text:#dbe7ff;--strong:#eaf2ff;--mute:#8d9bb8;--dim:#5d6b86;--line-rgb:148,163,184;--ok:#5eead4}
  :root[data-mode=light]{color-scheme:light;--bg:#f6f8fc;--text:#243447;--strong:#16222e;--mute:#55677e;--dim:#8593a8;--line-rgb:100,116,139;--ok:#0e8a72}
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
  <div class="bar">📝 <b>Notes</b> · local to this device <span class="saved" id="saved">saved</span></div>
  <textarea id="n" placeholder="Write something…"></textarea>
<script>
  var t=document.getElementById('n'), s=document.getElementById('saved'), K='semitexa-os-notes', tm;
  t.value=localStorage.getItem(K)||'';
  t.addEventListener('input',function(){
    localStorage.setItem(K,t.value);
    s.classList.add('show'); clearTimeout(tm); tm=setTimeout(function(){s.classList.remove('show')},900);
  });
  t.focus();
</script>
</body></html>
HTML;

        return $resource
            ->setContent($html)
            ->setHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
