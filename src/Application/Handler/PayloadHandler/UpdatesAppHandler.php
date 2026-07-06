<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Os\Application\Payload\Request\UpdatesAppPayload;
use Semitexa\Os\Application\Service\UpdatesReport;

/**
 * Renders the Updates / "What's new" dialog body: installed release set,
 * last update run, release notes for recently applied version changes, and
 * the run history. Standalone theme-aware HTML, same surface contract as
 * {@see SettingsAppHandler} (web-mode iframe or OS-mode native window).
 * Everything is server-rendered read-only — there is nothing to submit.
 */
#[AsPayloadHandler(payload: UpdatesAppPayload::class, resource: ResourceResponse::class)]
final class UpdatesAppHandler implements TypedHandlerInterface
{
    public function handle(UpdatesAppPayload $payload, ResourceResponse $resource): ResourceResponse
    {
        $report = (new UpdatesReport())->build(historyLimit: 12);
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

        $body = $report['available'] === true
            ? $this->renderReport($report, $e)
            : '<p class="empty">The update system (semitexa/update) is not installed on this OS build.</p>';

        $html = $this->page($body);

        $resource->setContent($html);
        $resource->setHeader('Content-Type', 'text/html; charset=utf-8');
        return $resource;
    }

    /**
     * @param array<string, mixed> $report
     * @param callable(string): string $e
     */
    private function renderReport(array $report, callable $e): string
    {
        $updater = (string) ($report['updater_version'] ?? '') ?: 'dev workspace';
        $releaseSet = (string) ($report['release_set'] ?? '') ?: 'n/a';
        $packages = (int) $report['packages'];

        $lastRun = 'never recorded';
        if (is_array($report['last_run'])) {
            $run = $report['last_run'];
            $lastRun = sprintf(
                '%s — %s%s (%s)',
                substr((string) $run['started_at'], 0, 19),
                (string) $run['outcome'],
                $run['failed_stage'] !== null ? ' @ ' . (string) $run['failed_stage'] : '',
                (string) $run['kind'],
            );
        }

        $head = '<div class="cards">'
            . '<div class="card"><div class="k">Updater</div><div class="v">' . $e($updater) . '</div></div>'
            . '<div class="card"><div class="k">Release set</div><div class="v">' . $e($releaseSet) . '</div>'
            . '<div class="k2">' . $packages . ' packages</div></div>'
            . '<div class="card"><div class="k">Last update run</div><div class="v">' . $e($lastRun) . '</div></div>'
            . '</div>';

        $notesHtml = '';
        foreach (array_slice((array) $report['notes'], 0, 6) as $note) {
            $notesHtml .= '<div class="note"><div class="note-head">' . $e((string) $note['package'])
                . ' <span class="ver">' . $e((string) $note['version']) . '</span>'
                . ($note['date'] !== null ? ' <span class="date">' . $e((string) $note['date']) . '</span>' : '')
                . '</div><pre>' . $e((string) $note['body']) . '</pre></div>';
        }
        if ($notesHtml === '') {
            $notesHtml = '<p class="empty">No release notes recorded yet — they appear after the first update that changes package versions.</p>';
        }

        $changesHtml = '';
        foreach (array_slice((array) $report['changes'], 0, 12) as $change) {
            $changesHtml .= '<tr><td>' . $e(substr((string) $change['at'], 0, 10)) . '</td>'
                . '<td>' . $e((string) $change['package']) . '</td>'
                . '<td>' . $e((string) ($change['from'] ?? '—')) . ' → ' . $e((string) $change['to']) . '</td></tr>';
        }

        $runsHtml = '';
        foreach ((array) $report['runs'] as $run) {
            $outcome = (string) $run['outcome'];
            $runsHtml .= '<tr><td>' . $e(substr((string) $run['started_at'], 0, 19)) . '</td>'
                . '<td>' . $e((string) $run['kind']) . '</td>'
                . '<td><span class="pill ' . $e($outcome) . '">' . $e($outcome)
                . ($run['failed_stage'] !== null ? ' @ ' . $e((string) $run['failed_stage']) : '') . '</span></td>'
                . '<td>' . (int) $run['deltas'] . '</td>'
                . '<td>' . ($run['duration_ms'] !== null ? (int) $run['duration_ms'] . 'ms' : '—') . '</td></tr>';
        }
        if ($runsHtml === '') {
            $runsHtml = '<tr><td colspan="5" class="empty">No update runs recorded yet.</td></tr>';
        }

        return $head
            . '<h3>What&#8217;s new</h3>' . $notesHtml
            . ($changesHtml !== '' ? '<h3>Version changes</h3><table><thead><tr><th>When</th><th>Package</th><th>Change</th></tr></thead><tbody>' . $changesHtml . '</tbody></table>' : '')
            . '<h3>Update history</h3><table><thead><tr><th>Started (UTC)</th><th>Kind</th><th>Outcome</th><th>Pkg Δ</th><th>Duration</th></tr></thead><tbody>'
            . $runsHtml . '</tbody></table>';
    }

    private function page(string $body): string
    {
        $shell = <<<'HTML'
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Updates</title>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<style>
  *{box-sizing:border-box} html,body{margin:0;height:100%}
  body{font-family:'IBM Plex Sans',system-ui,sans-serif;background:var(--bg);color:var(--text);padding:26px 26px 22px;font-size:14px;overflow-y:auto}
  h2{margin:0 0 2px;font-size:16px;font-weight:600;color:var(--strong)}
  .sub{color:var(--mute);font-size:12px;margin-bottom:20px}
  h3{margin:22px 0 10px;font-size:13px;font-weight:600;color:var(--text3);text-transform:uppercase;letter-spacing:.4px}
  .cards{display:flex;gap:12px;flex-wrap:wrap}
  .card{flex:1 1 150px;background:var(--well);border:1px solid rgba(var(--line-rgb),.18);border-radius:11px;padding:12px 14px}
  .k{font-size:11px;color:var(--mute);margin-bottom:4px}
  .k2{font-size:11px;color:var(--mute);margin-top:4px}
  .v{font-size:13px;color:var(--strong);font-weight:500;word-break:break-word}
  table{width:100%;border-collapse:collapse;font-size:12.5px}
  th{color:var(--mute);font-weight:500;text-align:left;padding:6px 10px;border-bottom:1px solid rgba(var(--line-rgb),.22)}
  td{padding:7px 10px;border-bottom:1px solid rgba(var(--line-rgb),.1);color:var(--text)}
  .pill{display:inline-block;padding:2px 9px;border-radius:999px;font-size:11px;font-weight:600}
  .pill.success{background:rgba(94,234,212,.14);color:var(--ok)}
  .pill.noop{background:rgba(var(--line-rgb),.14);color:var(--text3)}
  .pill.failed,.pill.aborted{background:rgba(255,154,171,.13);color:var(--err)}
  .pill.running{background:rgba(55,183,255,.13);color:var(--accent)}
  .note{background:var(--well);border:1px solid rgba(var(--line-rgb),.16);border-radius:11px;padding:12px 14px;margin-bottom:10px}
  .note-head{font-weight:600;color:var(--strong);margin-bottom:6px}
  .ver{color:var(--accent);font-weight:500}
  .date{color:var(--mute);font-size:11px;font-weight:400}
  pre{margin:0;white-space:pre-wrap;font:12px/1.55 'IBM Plex Sans',system-ui,sans-serif;color:var(--text);max-height:220px;overflow-y:auto}
  .empty{color:var(--mute);font-size:12.5px}
  :root{color-scheme:dark;--bg:#0c1020;--well:#0a0d18;--text:#cdd9ee;--strong:#eaf2ff;--mute:#6f7d99;--text3:#a8b4cc;--faint:#5b6782;
    --accent:#37b7ff;--line-rgb:148,163,184;--ok:#5eead4;--err:#ff9aab}
  :root[data-mode=light]{color-scheme:light;--bg:#f4f7fb;--well:#ffffff;--text:#243447;--strong:#16222e;--mute:#5b6c82;--text3:#3b4c61;--faint:#8593a8;
    --accent:#1e7fb8;--line-rgb:100,116,139;--ok:#0e8a72;--err:#b13a52}
</style><script>
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
  <h2>Updates</h2>
  <div class="sub">What changed on this system — release notes and update history.</div>
  __BODY__
</body></html>
HTML;

        return str_replace('__BODY__', $body, $shell);
    }
}
