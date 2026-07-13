<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Os\Application\Payload\Request\PromptsAppPayload;
use Semitexa\Prompt\Application\Service\PromptOverrideStore;
use Semitexa\Prompt\Application\Service\PromptRegistry;
use Semitexa\Prompt\Domain\Model\PromptTemplate;

/**
 * Renders the Prompts editor dialog body — a self-contained, interactive editor
 * that lists the prompt catalog and lets the operator override any prompt's
 * system text for the current tenant (saved to the semitexa-prompt DB override
 * layer). Standalone HTML because the Focus zone embeds it as an iframe.
 */
#[AsPayloadHandler(payload: PromptsAppPayload::class, resource: ResourceResponse::class)]
final class PromptsAppHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected PromptOverrideStore $overrides;

    public function handle(PromptsAppPayload $payload, ResourceResponse $resource): ResourceResponse
    {
        $data = json_encode(
            ['prompts' => $this->catalogView()],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
        );

        $html = str_replace('__PROMPTS_DATA__', (string) $data, $this->page());

        return $resource
            ->setContent($html)
            ->setHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * The catalog overlaid with the current tenant's overrides.
     *
     * @return list<array{id: string, channel: string, description: string, variables: list<string>, base: string|null, override: string|null}>
     */
    private function catalogView(): array
    {
        $catalog = (new PromptRegistry())->all();
        $overrides = $this->safeOverrides();

        $rows = [];
        $seen = [];
        foreach ($catalog as $template) {
            $seen[$template->id] = true;
            $rows[] = $this->row($template, $overrides[$template->id] ?? null);
        }

        // Overrides for prompts no longer in the catalog: keep them visible so
        // they can be inspected / reset rather than silently orphaned.
        foreach ($overrides as $id => $system) {
            if (!isset($seen[$id])) {
                $rows[] = [
                    'id' => $id,
                    'channel' => '(orphan)',
                    'description' => 'Override with no matching catalog prompt.',
                    'variables' => [],
                    'base' => null,
                    'override' => $system,
                ];
            }
        }

        usort($rows, static fn(array $a, array $b): int => strcmp($a['id'], $b['id']));

        return $rows;
    }

    /**
     * @return array{id: string, channel: string, description: string, variables: list<string>, base: string|null, override: string|null}
     */
    private function row(PromptTemplate $template, ?string $override): array
    {
        return [
            'id' => $template->id,
            'channel' => $template->channel,
            'description' => $template->description,
            'variables' => $template->variableNames(),
            'base' => $template->system,
            'override' => $override,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function safeOverrides(): array
    {
        try {
            return $this->overrides->all();
        } catch (\Throwable) {
            // The editor must still open (to catalog) even if the DB is down.
            return [];
        }
    }

    private function page(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Prompts</title>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
  *{box-sizing:border-box} html,body{margin:0;height:100%}
  body{font-family:'IBM Plex Sans',system-ui,sans-serif;background:var(--bg);color:var(--text);display:flex;height:100vh;overflow:hidden}
  :root{color-scheme:dark;--bg:#1e1e2e;--panel:#22222f;--text:#dbe7ff;--strong:#eaf2ff;--mute:#8d9bb8;--dim:#5d6b86;--line-rgb:148,163,184;--accent:#7aa2ff;--ok:#5eead4;--warn:#fbbf24}
  :root[data-mode=light]{color-scheme:light;--bg:#f6f8fc;--panel:#eef2f8;--text:#243447;--strong:#16222e;--mute:#55677e;--dim:#8593a8;--line-rgb:100,116,139;--accent:#2f6bff;--ok:#0e8a72;--warn:#b45309}
  .side{width:280px;min-width:280px;border-right:1px solid rgba(var(--line-rgb),.18);display:flex;flex-direction:column;background:var(--panel)}
  .side h1{font-size:13px;letter-spacing:.04em;text-transform:uppercase;color:var(--mute);margin:0;padding:14px 16px 8px}
  .search{margin:0 12px 8px;padding:7px 10px;border:1px solid rgba(var(--line-rgb),.22);border-radius:8px;background:transparent;color:var(--strong);font:inherit;font-size:13px;outline:none}
  .list{flex:1;overflow-y:auto;padding:0 8px 12px}
  .item{display:flex;align-items:center;gap:8px;padding:9px 10px;border-radius:8px;cursor:pointer;color:var(--text)}
  .item:hover{background:rgba(var(--line-rgb),.10)}
  .item.sel{background:rgba(var(--accent),.16);color:var(--strong)}
  .item .id{font-family:'IBM Plex Mono',monospace;font-size:12.5px;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .item .ch{font-size:10px;color:var(--dim);text-transform:uppercase;letter-spacing:.03em}
  .dot{width:7px;height:7px;border-radius:50%;background:var(--warn);flex:0 0 auto;visibility:hidden}
  .item.ov .dot{visibility:visible}
  .main{flex:1;display:flex;flex-direction:column;min-width:0}
  .head{padding:16px 20px 12px;border-bottom:1px solid rgba(var(--line-rgb),.18)}
  .head .pid{font-family:'IBM Plex Mono',monospace;font-size:15px;color:var(--strong);font-weight:500}
  .head .desc{font-size:13px;color:var(--mute);margin-top:4px}
  .meta{display:flex;flex-wrap:wrap;gap:6px;margin-top:10px}
  .chip{font-family:'IBM Plex Mono',monospace;font-size:11px;padding:2px 7px;border-radius:6px;background:rgba(var(--line-rgb),.14);color:var(--mute)}
  .chip.v{color:var(--accent);background:rgba(var(--accent),.12)}
  .badge{font-size:11px;padding:2px 8px;border-radius:20px;font-weight:600}
  .badge.ov{background:rgba(var(--warn),.16);color:var(--warn)}
  .badge.base{background:rgba(var(--line-rgb),.14);color:var(--mute)}
  .edit{flex:1;display:flex;flex-direction:column;padding:14px 20px;min-height:0}
  .lbl{font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--dim);margin-bottom:6px;display:flex;align-items:center;gap:8px}
  textarea{flex:1;width:100%;border:1px solid rgba(var(--line-rgb),.22);border-radius:10px;outline:none;resize:none;background:var(--panel);color:var(--strong);
    font-family:'IBM Plex Mono',monospace;font-size:13px;line-height:1.7;padding:14px 16px;tab-size:2}
  textarea:focus{border-color:rgba(var(--accent),.6)}
  .foot{display:flex;align-items:center;gap:10px;padding:12px 20px 16px;border-top:1px solid rgba(var(--line-rgb),.18)}
  button{font:inherit;font-size:13px;font-weight:500;padding:8px 16px;border-radius:8px;border:1px solid transparent;cursor:pointer}
  button.primary{background:var(--accent);color:#fff}
  button.primary:disabled{opacity:.45;cursor:not-allowed}
  button.ghost{background:transparent;color:var(--mute);border-color:rgba(var(--line-rgb),.25)}
  button.ghost:disabled{opacity:.4;cursor:not-allowed}
  .status{margin-left:auto;font-family:'IBM Plex Mono',monospace;font-size:11.5px;color:var(--ok);opacity:0;transition:opacity .3s}
  .status.show{opacity:1}
  .status.err{color:var(--warn)}
  .empty{margin:auto;color:var(--dim);font-size:14px;text-align:center}
  details{margin-top:12px}
  summary{cursor:pointer;font-size:12px;color:var(--mute);user-select:none}
  pre.base{margin:8px 0 0;padding:12px 14px;border-radius:8px;background:rgba(var(--line-rgb),.10);color:var(--mute);
    font-family:'IBM Plex Mono',monospace;font-size:12px;line-height:1.6;white-space:pre-wrap;max-height:200px;overflow:auto}
  .histlist{margin-top:8px;display:flex;flex-direction:column;gap:4px}
  .histrow{display:flex;align-items:center;gap:10px;padding:6px 10px;border-radius:7px;background:rgba(var(--line-rgb),.08);font-size:12px}
  .histrow .hv{font-family:'IBM Plex Mono',monospace;color:var(--accent);min-width:34px;font-weight:500}
  .histrow .ht{color:var(--dim);font-family:'IBM Plex Mono',monospace;font-size:11px;white-space:nowrap}
  .histrow .hs{flex:1;color:var(--mute);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-family:'IBM Plex Mono',monospace}
  .histrow button{padding:3px 10px;font-size:11px}
  .histmute{color:var(--dim);font-size:12px;padding:4px 2px}
</style></head>
<body>
  <aside class="side">
    <h1>Prompt catalog</h1>
    <input class="search" id="search" placeholder="Filter prompts…" autocomplete="off">
    <div class="list" id="list"></div>
  </aside>
  <main class="main" id="main">
    <div class="empty" id="empty">Select a prompt to view or override it.</div>
  </main>
  <script type="application/json" id="prompts-data">__PROMPTS_DATA__</script>
  <script>
  (function(){
    var DATA = JSON.parse(document.getElementById('prompts-data').textContent).prompts || [];
    var byId = {}; DATA.forEach(function(p){ byId[p.id] = p; });
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
          // A restore appends a new version carrying the old text; reflect it as
          // the current override by reading the newest history entry back.
          var hr = await fetch('/os/prompts/history?id='+encodeURIComponent(id), {headers:{'Accept':'application/json'}});
          var hd = await hr.json(); var top = (hd.versions||[])[0];
          if(top) byId[id].override = top.system;
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
        headers:{'Content-Type':'application/json','Accept':'application/json'}, body: JSON.stringify(body)});
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

    function notify(id){ try{ window.parent.postMessage({type:'os:prompt-override-changed', id:id}, '*'); }catch(e){} }

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
  </script>
</body></html>
HTML;
    }
}
