<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Llm\Application\Service\SkillRegistry;
use Semitexa\Os\Application\Payload\Request\TerminalAppPayload;

/**
 * Renders the Terminal dialog body — a console-skill terminal. Lists the
 * `console`-channel skills (the ones kept out of the main intent-first UI) and
 * runs them via POST /os/skill, streaming output into a log. Standalone HTML
 * (the Focus zone embeds it as an iframe).
 */
#[AsPayloadHandler(payload: TerminalAppPayload::class, resource: ResourceResponse::class)]
final class TerminalAppHandler implements TypedHandlerInterface
{
    public function handle(TerminalAppPayload $payload, ResourceResponse $resource): ResourceResponse
    {
        $manifest = (new SkillRegistry())->buildManifest();
        $console = [];
        foreach ($manifest->skills as $skill) {
            if (!$skill->isUi() && in_array('console', $skill->channels, true)) {
                $console[] = [
                    'name' => $skill->name,
                    'summary' => $skill->summary,
                    'risk' => $skill->riskLevel->value,
                ];
            }
        }
        $skillsJson = (string) json_encode($console, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $html = <<<'HTML'
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Terminal</title>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans:wght@400;500&display=swap" rel="stylesheet">
<style>
  *{box-sizing:border-box} html,body{margin:0;height:100%}
  body{font-family:'IBM Plex Mono',ui-monospace,monospace;background:var(--bg);color:var(--text);display:flex;flex-direction:column;font-size:13px}
  .hint{padding:10px 14px;border-bottom:1px solid rgba(var(--line-rgb),.16);color:var(--mute);font-family:'IBM Plex Sans',sans-serif;font-size:12px;display:flex;flex-wrap:wrap;gap:6px;align-items:center}
  .hint b{color:var(--text3)}
  .chip{cursor:pointer;color:var(--accent);border:1px solid rgba(var(--accent-rgb),.3);border-radius:6px;padding:2px 8px}
  .chip:hover{background:rgba(var(--accent-rgb),.12)}
  .out{flex:1;overflow:auto;padding:14px;white-space:pre-wrap;line-height:1.55}
  .cmd{color:var(--cmd)} .err{color:var(--err)} .muted{color:var(--mute)}
  .row{display:flex;align-items:center;gap:8px;padding:10px 14px;border-top:1px solid rgba(var(--line-rgb),.16);background:var(--bg2)}
  .row span{color:var(--accent)}
  input{flex:1;background:transparent;border:none;outline:none;color:var(--strong);font-family:'IBM Plex Mono',monospace;font-size:13px}
  :root{color-scheme:dark;--bg:#0c1020;--bg2:#0a0d18;--text:#cdd9ee;--strong:#eaf2ff;--mute:#6f7d99;--text3:#a8b4cc;
    --accent:#37b7ff;--accent-rgb:55,183,255;--line-rgb:148,163,184;--cmd:#5eead4;--err:#ff9aab}
  :root[data-mode=light]{color-scheme:light;--bg:#f4f7fb;--bg2:#e9eff7;--text:#243447;--strong:#16222e;--mute:#5b6c82;--text3:#3b4c61;
    --accent:#1e7fb8;--accent-rgb:30,127,184;--line-rgb:100,116,139;--cmd:#0e8a72;--err:#b13a52}
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
  <div class="hint"><b>console skills:</b> <span id="chips"></span></div>
  <div class="out" id="out"><span class="muted">Type a skill name and press Enter. Console skills run here — out of the main UI.</span></div>
  <div class="row"><span>&gt;</span><input id="cmd" placeholder="skill name…" autocomplete="off" autofocus></div>
<script>
  var SKILLS = %SKILLS%;
  var out=document.getElementById('out'), cmd=document.getElementById('cmd'), chips=document.getElementById('chips');
  function esc(s){return String(s==null?'':s).replace(/[&<>]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;'}[c]})}
  SKILLS.forEach(function(s){var b=document.createElement('span');b.className='chip';b.textContent=s.name;b.title=s.summary||'';b.onclick=function(){cmd.value=s.name;cmd.focus()};chips.appendChild(b)});
  function line(html){out.innerHTML+='\n'+html;out.scrollTop=out.scrollHeight}
  async function run(name){
    name=(name||'').trim(); if(!name) return;
    line('<span class="cmd">&gt; '+esc(name)+'</span>');
    if(!SKILLS.some(function(s){return s.name===name})){ line('<span class="err">unknown console skill: '+esc(name)+'</span>'); return; }
    line('<span class="muted">running…</span>');
    try{
      var r=await fetch('/os/skill',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({intent:'(terminal) '+name,skill:name,arguments:{}})});
      var d=await r.json();
      var o=(d.output||'').replace(/\s+$/,'');
      line(o?esc(o):'<span class="muted">(no output)</span>');
      if(d.error) line('<span class="err">'+esc(d.error)+'</span>');
      line('<span class="muted">exit '+(d.exit_code==null?'?':d.exit_code)+'</span>');
    }catch(e){ line('<span class="err">'+esc(e.message)+'</span>'); }
  }
  cmd.addEventListener('keydown',function(e){ if(e.key==='Enter'){ var v=cmd.value; cmd.value=''; run(v); } });
</script>
</body></html>
HTML;

        return $resource
            ->setContent(str_replace('%SKILLS%', $skillsJson, $html))
            ->setHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
