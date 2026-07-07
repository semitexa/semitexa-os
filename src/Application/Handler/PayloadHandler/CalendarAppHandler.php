<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Os\Application\Payload\Request\CalendarAppPayload;
use Semitexa\Ssr\Application\Service\Asset\AssetManager;

/**
 * Renders the Calendar dialog body — hosts the real `platform.calendar`
 * component (the OS dogfoods the platform component) by mounting its shell +
 * loading the platform-ui calendar assets, tinted to the OS dark palette via
 * `--ui-*` overrides. Standalone HTML embedded as an iframe by the Focus zone.
 */
#[AsPayloadHandler(payload: CalendarAppPayload::class, resource: ResourceResponse::class)]
final class CalendarAppHandler implements TypedHandlerInterface
{
    public function handle(CalendarAppPayload $payload, ResourceResponse $resource): ResourceResponse
    {
        $html = <<<'HTML'
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Calendar</title>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="__CALENDAR_CSS__">
<style>
  /* Tint the platform calendar to the OS dark palette. */
  :root {
    /* Render native form controls (datetime-local text + picker glyph, select,
       checkbox, scrollbars, the date-picker popup) in dark mode so they stay
       legible on the dark surfaces below. */
    color-scheme: dark;
    --ui-font-sans: 'IBM Plex Sans', system-ui, sans-serif;
    --ui-text-primary: #eaf2ff;
    --ui-text-muted: #8d9bb8;
    --ui-surface-page: #0c1020;
    --ui-surface-panel: #0f172a;
    --ui-surface-raised: #1a2436;
    --ui-surface-sunken: rgba(148,163,184,.06);
    --ui-border-subtle: rgba(148,163,184,.18);
    --ui-radius-md: 9px;
    --ui-radius-lg: 14px;
    --ui-accent-brand: #37b7ff;
    --ui-state-danger: #ff6b82;
  }
  html, body { margin: 0; height: 100%; background: var(--ui-surface-page); }
  body { padding: 14px; box-sizing: border-box; font-family: var(--ui-font-sans); }
  .uical { height: 100%; }
  :root[data-mode=light]{
    color-scheme:light;
    --ui-text-primary:#1d2a38; --ui-text-muted:#55677e;
    --ui-surface-page:#f4f7fb; --ui-surface-panel:#ffffff; --ui-surface-raised:#e9eff7;
    --ui-surface-sunken:rgba(100,116,139,.08); --ui-border-subtle:rgba(100,116,139,.28);
    --ui-accent-brand:#1e7fb8; --ui-state-danger:#c2314b;
  }
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
  <div
    data-ui-calendar="1"
    data-ui-calendar-endpoint="/platform/calendar/events"
    data-ui-calendar-save="/platform/calendar/events/save"
    data-ui-calendar-delete="/platform/calendar/events/delete"
    data-ui-calendar-view="month"
    data-ui-calendar-live="0"
    class="uical"></div>
  <script type="importmap">__IMPORT_MAP__</script>
  <script type="module" src="__CALENDAR_RUNTIME_JS__"></script>
</body></html>
HTML;

        // Fingerprinted URLs so StaticAssetHandler can serve the platform-ui
        // assets with immutable caching (raw /assets/ URLs forfeit that).
        // calendar-runtime is an ES module importing 'platform-ui/core' and
        // 'platform-ui/dates' — the import map (which must precede the module
        // tag) resolves those to fingerprinted URLs; the import graph loads
        // them, so only the runtime needs a script tag.
        $importMap = json_encode([
            'imports' => [
                'platform-ui/core' => AssetManager::getUrl('js/ui-core.js', 'platform-ui'),
                'platform-ui/dates' => AssetManager::getUrl('js/calendar-dates.js', 'platform-ui'),
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $html = strtr($html, [
            '__CALENDAR_CSS__' => AssetManager::getUrl('css/calendar.css', 'platform-ui'),
            '__IMPORT_MAP__' => $importMap,
            '__CALENDAR_RUNTIME_JS__' => AssetManager::getUrl('js/calendar-runtime.js', 'platform-ui'),
        ]);

        return $resource
            ->setContent($html)
            ->setHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
