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
  body{font-family:'IBM Plex Sans',system-ui,sans-serif;background:#1e1e2e;color:#dbe7ff;display:flex;flex-direction:column}
  .bar{display:flex;align-items:center;gap:10px;padding:10px 14px;border-bottom:1px solid rgba(148,163,184,.18);font-size:12px;color:#8d9bb8}
  .bar b{color:#dbe7ff;font-weight:600}
  .saved{margin-left:auto;font-family:'IBM Plex Mono',monospace;font-size:11px;color:#5eead4;opacity:0;transition:opacity .3s}
  .saved.show{opacity:1}
  textarea{flex:1;width:100%;border:none;outline:none;resize:none;background:transparent;color:#eaf2ff;
    font-family:'IBM Plex Sans',sans-serif;font-size:15px;line-height:1.7;padding:16px 18px}
  textarea::placeholder{color:#5d6b86}
</style></head>
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
