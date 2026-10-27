<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsMutable;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\CspNonce;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\Session\SessionInterface;
use Semitexa\Llm\Application\Service\TenantSkillScope;
use Semitexa\Os\Application\Payload\Request\TerminalAppPayload;
use Semitexa\Llm\Domain\Model\SkillManifest;
use Semitexa\Os\Application\Service\OsSkillText;
use Semitexa\Os\Application\Service\OsSkillScope;
use Semitexa\Ssr\Application\Service\Asset\AssetManager;

/**
 * Renders the Terminal dialog body — a console-skill terminal. Lists the
 * `console`-channel skills (the ones kept out of the main intent-first UI) and
 * runs them via POST /os/terminal/run (TerminalRunHandler), printing the
 * output into a log. Standalone HTML (the Focus zone embeds it as an iframe).
 */
#[AsPayloadHandler(payload: TerminalAppPayload::class, resource: ResourceResponse::class)]
final class TerminalAppHandler implements TypedHandlerInterface
{
    #[InjectAsMutable]
    protected SessionInterface $session;

    #[InjectAsReadonly]
    protected OsSkillScope $skillScope;

    #[InjectAsReadonly]
    protected TenantSkillScope $skills;

    public function handle(TerminalAppPayload $payload, ResourceResponse $resource): ResourceResponse
    {
        $scope = $this->skillScope->forSession(isset($this->session) ? $this->session : null);
        $manifest = $scope === null
            ? SkillManifest::emptyFor(['console'])
            : $this->skills->manifestFor($scope, ['console']);
        $console = [];
        foreach ($manifest->skills() as $skill) {
            // The manifest is already scoped to 'console'; isUi() still matters
            // because a skill may declare both and a UI skill has no command to
            // type at a terminal.
            if (!$skill->isUi()) {
                $console[] = [
                    'name' => $skill->name,
                    'summary' => OsSkillText::summary($skill),
                    'risk' => $skill->riskLevel->value,
                ];
            }
        }
        // JSON_HEX_TAG: it sits in a data block, where "</script>" in a summary
        // would end the block.
        $skillsJson = (string) json_encode($console, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);

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
</style><script src="__THEME_SYNC_JS__"></script>
</head>
<body>
  <div class="hint"><b>console skills:</b> <span id="chips"></span></div>
  <div class="out" id="out"><span class="muted">Type a skill name and press Enter. Console skills run here — out of the main UI.</span></div>
  <div class="row"><span>&gt;</span><input id="cmd" placeholder="skill name…" autocomplete="off" autofocus></div>
<script type="application/json" id="terminal-skills">%SKILLS%</script>
<script src="__TERMINAL_APP_JS__"></script>
</body></html>
HTML;

        return $resource
            ->setContent(CspNonce::stamp(strtr($html, [
                '%SKILLS%' => $skillsJson,
                '__THEME_SYNC_JS__' => AssetManager::getUrl('js/app-theme-sync.js', 'os'),
                '__TERMINAL_APP_JS__' => AssetManager::getUrl('js/terminal-app.js', 'os'),
            ])))
            ->setHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
