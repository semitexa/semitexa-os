<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Os\Application\Payload\Request\CalendarAppPayload;

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
<link rel="stylesheet" href="/assets/platform-ui/css/calendar.css">
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
  html, body { margin: 0; height: 100%; background: #0c1020; }
  body { padding: 14px; box-sizing: border-box; font-family: var(--ui-font-sans); }
  .uical { height: 100%; }
</style></head>
<body>
  <div
    data-ui-calendar="1"
    data-ui-calendar-endpoint="/platform/calendar/events"
    data-ui-calendar-save="/platform/calendar/events/save"
    data-ui-calendar-delete="/platform/calendar/events/delete"
    data-ui-calendar-view="month"
    class="uical"></div>
  <script src="/assets/platform-ui/js/calendar-dates.js"></script>
  <script src="/assets/platform-ui/js/calendar-runtime.js"></script>
</body></html>
HTML;

        return $resource
            ->setContent($html)
            ->setHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
