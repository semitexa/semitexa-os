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
  body{font-family:'IBM Plex Mono',ui-monospace,monospace;background:#0c1020;color:#cdd9ee;display:flex;flex-direction:column;font-size:13px}
  .hint{padding:10px 14px;border-bottom:1px solid rgba(148,163,184,.16);color:#6f7d99;font-family:'IBM Plex Sans',sans-serif;font-size:12px;display:flex;flex-wrap:wrap;gap:6px;align-items:center}
  .hint b{color:#a8b4cc}
  .chip{cursor:pointer;color:#37b7ff;border:1px solid rgba(55,183,255,.3);border-radius:6px;padding:2px 8px}
  .chip:hover{background:rgba(55,183,255,.12)}
  .out{flex:1;overflow:auto;padding:14px;white-space:pre-wrap;line-height:1.55}
  .cmd{color:#5eead4} .err{color:#ff9aab} .muted{color:#6f7d99}
  .row{display:flex;align-items:center;gap:8px;padding:10px 14px;border-top:1px solid rgba(148,163,184,.16);background:#0a0d18}
  .row span{color:#37b7ff}
  input{flex:1;background:transparent;border:none;outline:none;color:#eaf2ff;font-family:'IBM Plex Mono',monospace;font-size:13px}
</style></head>
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
