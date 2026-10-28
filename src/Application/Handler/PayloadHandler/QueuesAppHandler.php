<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsMutable;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\CspNonce;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\Queue\QueueInspection;
use Semitexa\Core\Queue\QueueStatsReport;
use Semitexa\Core\Session\SessionInterface;
use Semitexa\Os\Application\Payload\Request\QueuesAppPayload;
use Semitexa\Os\Application\Service\OsSkillScope;
use Semitexa\Ssr\Application\Service\Asset\AssetManager;

/**
 * The Queues dialog body: what `queue:stats` and `queue:failed` print, for a
 * person who is not at a terminal. Read-only, like the Updates app: retrying
 * or forgetting a dead letter stays on `queue:retry`. Errors are cut to 200
 * characters and payloads are never shown.
 *
 * Operator only (an unrestricted OS scope): the queues are the whole
 * server's, and a tenant's admin would read every tenant's job errors.
 */
#[AsPayloadHandler(payload: QueuesAppPayload::class, resource: ResourceResponse::class)]
final class QueuesAppHandler implements TypedHandlerInterface
{
    private const WINDOW_MINUTES = 15;
    private const DEAD_LETTERS = 5;

    #[InjectAsReadonly]
    protected QueueInspection $inspection;

    #[InjectAsReadonly]
    protected OsSkillScope $scope;

    #[InjectAsMutable]
    protected SessionInterface $session;

    public function handle(QueuesAppPayload $payload, ResourceResponse $resource): ResourceResponse
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $cut = static fn (string $v): string => mb_strlen($v) > 200 ? mb_substr($v, 0, 200) . '…' : $v;

        $scope = isset($this->scope) ? $this->scope->forSession(isset($this->session) ? $this->session : null) : null;
        if ($scope === null || !$scope->unrestricted) {
            $resource->setStatusCode(403);
            $resource->setContent(CspNonce::stamp($this->page('<p class="empty">Queues is for the operator: it shows every tenant\'s jobs.</p>')));
            $resource->setHeader('Content-Type', 'text/html; charset=utf-8');

            return $resource;
        }

        $report = QueueStatsReport::build($this->inspection, self::WINDOW_MINUTES);

        $rows = '';
        $countersError = null;
        foreach ($report['queues'] as $q) {
            $countersError ??= $q['counters_error'];
            $rows .= '<tr><td>' . $e($q['transport'] . '/' . $q['queue'])
                . ($q['error'] !== null ? '<div class="k2 bad">could not be read: ' . $e($cut($q['error'])) . '</div>' : '') . '</td>'
                . '<td class="num">' . ($q['processed'] ?? '?') . '</td>'
                . '<td class="num">' . ($q['retried'] ?? '?') . '</td>'
                . '<td class="num' . ($q['dead_lettered'] > 0 ? ' bad' : '') . '">' . ($q['dead_lettered'] ?? '?') . '</td>'
                . '<td class="num">' . $e((string) ($q['per_minute'] ?? '?')) . '</td>'
                . '<td class="num">' . $e(QueueStatsReport::ms($q['wait_ms']['avg']) . ' · ' . QueueStatsReport::ms($q['wait_ms']['p95'])) . '</td>'
                . '<td class="num">' . $e(QueueStatsReport::ms($q['run_ms']['avg']) . ' · ' . QueueStatsReport::ms($q['run_ms']['p95'])) . '</td>'
                . '<td class="num">' . ($q['waiting'] ?? '?') . '</td>'
                . '<td class="num">' . ($q['oldest_waiting_seconds'] !== null ? $q['oldest_waiting_seconds'] . ' s' : '') . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="9" class="empty">No queue transport is configured (EVENTS_ASYNC=0 runs jobs in the request).</td></tr>';
        }

        // Not for a queue whose transport could not be read above: it would be
        // waited on a second time. (Counters that failed do not count: they
        // live elsewhere.) A read that fails is said, not taken for "nothing
        // failed".
        $unreadable = [];
        foreach ($report['queues'] as $q) {
            if ($q['error'] !== null) {
                $unreadable[$q['transport'] . '/' . $q['queue']] = true;
            }
        }
        $failed = '';
        $unread = [];
        foreach ($this->inspection->targets() as $target) {
            $name = $target['transport'] . '/' . $target['queue'];
            $inspector = $target['inspector'];
            if ($inspector === null || isset($unreadable[$name])) {
                $unread[] = $name;
                continue;
            }
            try {
                $letters = $inspector->deadLetters($target['queue'], self::DEAD_LETTERS);
            } catch (\Throwable $ex) {
                $unread[] = $name . ' (' . $cut($ex->getMessage()) . ')';
                continue;
            }
            foreach ($letters as $letter) {
                $failed .= '<tr><td>' . $e($letter->failedAt?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s') ?? '') . '</td>'
                    . '<td>' . $e($target['queue']) . '</td>'
                    . '<td>' . $e($letter->target) . '</td>'
                    . '<td class="num">' . $letter->attempts . '</td>'
                    . '<td>' . $e($cut($letter->error)) . '</td></tr>';
            }
        }
        $unreadNote = $unread !== [] ? '<p class="empty bad">Not read: ' . $e(implode(', ', $unread)) . '.</p>' : '';

        $body = '<div class="cards"><div class="card"><div class="k">Window</div><div class="v">last ' . self::WINDOW_MINUTES . ' min</div></div>'
            . '<div class="card"><div class="k">Counters</div><div class="v">' . $e($report['store'] === 'off' ? 'not recorded (QUEUE_STATS_STORE=off)' : $report['store']) . '</div>'
            . ($countersError !== null ? '<div class="k2 bad">could not be read: ' . $e($cut($countersError)) . '</div>' : '') . '</div></div>'
            . '<h3>Queues</h3><table><thead><tr><th>Queue</th><th class="num">Done</th><th class="num">Retried</th><th class="num">Dead</th><th class="num">/min</th><th class="num">Wait avg · p95</th><th class="num">Run avg · p95</th><th class="num">Waiting</th><th class="num">Oldest</th></tr></thead><tbody>'
            . $rows . '</tbody></table>'
            . '<h3>Dead-lettered</h3>'
            . ($failed !== ''
                ? '<table><thead><tr><th>Failed (UTC)</th><th>Queue</th><th>Job</th><th class="num">Attempts</th><th>Error</th></tr></thead><tbody>' . $failed . '</tbody></table>'
                    . '<p class="empty">Retry or forget one with <code>bin/semitexa queue:retry</code>.</p>'
                : ($unread === [] ? '<p class="empty">Nothing dead-lettered.</p>' : ''))
            . $unreadNote;

        $resource->setContent(CspNonce::stamp($this->page($body)));
        $resource->setHeader('Content-Type', 'text/html; charset=utf-8');

        return $resource;
    }

    private function page(string $body): string
    {
        $shell = <<<'HTML'
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Queues</title>
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
  .num{text-align:right;font-variant-numeric:tabular-nums}
  .bad{color:var(--err);font-weight:600}
  .empty{color:var(--mute);font-size:12.5px}
  :root{color-scheme:dark;--bg:#0c1020;--well:#0a0d18;--text:#cdd9ee;--strong:#eaf2ff;--mute:#6f7d99;--text3:#a8b4cc;--faint:#5b6782;
    --accent:#37b7ff;--line-rgb:148,163,184;--ok:#5eead4;--err:#ff9aab}
  :root[data-mode=light]{color-scheme:light;--bg:#f4f7fb;--well:#ffffff;--text:#243447;--strong:#16222e;--mute:#5b6c82;--text3:#3b4c61;--faint:#8593a8;
    --accent:#1e7fb8;--line-rgb:100,116,139;--ok:#0e8a72;--err:#b13a52}
</style><script src="__THEME_SYNC_JS__"></script>
</head>
<body>
  <h2>Queues</h2>
  <div class="sub">Background jobs over the last 15 minutes, and what is waiting or failed now.</div>
  __BODY__
</body></html>
HTML;

        // strtr, one pass: the body carries job errors and must not be
        // scanned for the asset placeholder.
        return strtr($shell, [
            '__BODY__' => $body,
            '__THEME_SYNC_JS__' => AssetManager::getUrl('js/app-theme-sync.js', 'os'),
        ]);
    }
}
