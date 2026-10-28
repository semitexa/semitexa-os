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
use Semitexa\Os\Application\Payload\Request\SettingsAppPayload;
use Semitexa\Os\Application\Service\OsAdminSession;
use Semitexa\Os\Application\Service\OsPreferences;
use Semitexa\Ssr\Application\Service\Asset\AssetManager;

/**
 * Renders the Settings dialog body — the OS personalisation form (the
 * assistant's name and the user's own name) and, for a signed-in account, its
 * password. Standalone HTML embedded as an
 * iframe by the Focus zone (web mode) or opened as its own native window (OS
 * mode). On save it POSTs to /os/preferences — the source of truth — and, in
 * web mode, postMessages the parent shell for an instant update. In OS mode the
 * shell re-syncs from /os/preferences when it regains focus (it is a separate
 * process, so the postMessage cannot reach it).
 */
#[AsPayloadHandler(payload: SettingsAppPayload::class, resource: ResourceResponse::class)]
final class SettingsAppHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected OsPreferences $prefs;

    #[InjectAsReadonly]
    protected OsAdminSession $admins;

    #[InjectAsMutable]
    protected SessionInterface $session;

    public function handle(SettingsAppPayload $payload, ResourceResponse $resource): ResourceResponse
    {
        $prefs = $this->prefs->all();
        $enc = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $namesJson = (string) json_encode(
            ['assistant' => $prefs['assistant_name'], 'user' => $prefs['user_name']],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG,
        );

        // A signed-in account changes its own password here (POST /os/password).
        // The route had no form: a user whose password an operator issued was
        // sent here after signing in, and nothing else in the console opens
        // for them until they replace it.
        $user = isset($this->admins) ? $this->admins->currentHere(isset($this->session) ? $this->session : null) : null;
        $mustChange = $user !== null && $user->isPasswordIssuedByOperator();
        $passwordSection = $user === null ? '' : str_replace('%MUST_CHANGE%', $mustChange ? '1' : '0', self::PASSWORD_SECTION);
        $notice = $mustChange
            ? '<div class="notice">Your password was set by your operator. Choose your own to use the console.</div>'
            : '';

        $html = <<<'HTML'
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Settings</title>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<style>
  *{box-sizing:border-box} html,body{margin:0;height:100%}
  body{font-family:'IBM Plex Sans',system-ui,sans-serif;background:var(--bg);color:var(--text);padding:26px 26px 22px;font-size:14px}
  h2{margin:0 0 2px;font-size:16px;font-weight:600;color:var(--strong)}
  .sub{color:var(--mute);font-size:12px;margin-bottom:22px}
  .field{margin-bottom:18px}
  label{display:block;font-size:12px;color:var(--text3);margin-bottom:7px}
  input{width:100%;background:var(--well);border:1px solid rgba(var(--line-rgb),.22);border-radius:9px;padding:11px 13px;color:var(--strong);font:inherit;outline:none}
  input:focus{border-color:var(--accent)}
  .actions{display:flex;align-items:center;gap:12px;margin-top:4px}
  button{background:var(--accent);border:none;border-radius:9px;padding:11px 20px;color:var(--btn-text);font:inherit;font-weight:600;cursor:pointer}
  button:disabled{opacity:.5;cursor:default}
  .hint{font-size:12px;min-height:16px}
  .ok{color:var(--ok)} .err{color:var(--err)}
  .note{margin-top:20px;font-size:11px;color:var(--faint);line-height:1.5}
  .notice{margin-bottom:20px;padding:11px 13px;border-radius:9px;border:1px solid var(--accent);color:var(--strong);font-size:13px}
  h3{margin:28px 0 14px;font-size:14px;font-weight:600;color:var(--strong)}
  :root{color-scheme:dark;--bg:#0c1020;--well:#0a0d18;--text:#cdd9ee;--strong:#eaf2ff;--mute:#6f7d99;--text3:#a8b4cc;--faint:#5b6782;
    --accent:#37b7ff;--btn-text:#04121f;--line-rgb:148,163,184;--ok:#5eead4;--err:#ff9aab}
  :root[data-mode=light]{color-scheme:light;--bg:#f4f7fb;--well:#ffffff;--text:#243447;--strong:#16222e;--mute:#5b6c82;--text3:#3b4c61;--faint:#8593a8;
    --accent:#1e7fb8;--btn-text:#ffffff;--line-rgb:100,116,139;--ok:#0e8a72;--err:#b13a52}
</style><script src="__THEME_SYNC_JS__"></script>
</head>
<body>
  <h2>Settings</h2>
  <div class="sub">Personalise your OS.</div>
  %NOTICE%
  <div class="field">
    <label for="assistant">Assistant name</label>
    <input id="assistant" value="%ANAME_ATTR%" maxlength="24" autocomplete="off" spellcheck="false" placeholder="Solomiia">
  </div>
  <div class="field">
    <label for="user">Your name</label>
    <input id="user" value="%UNAME_ATTR%" maxlength="24" autocomplete="off" spellcheck="false" placeholder="What should I call you?">
  </div>
  <div class="actions"><button id="save">Save</button><span class="hint" id="hint"></span></div>
  <div class="note">You can also just say it: “call yourself Jarvis”, or “my name is Alex”.</div>
  %PASSWORD_SECTION%
<script type="application/json" id="settings-names">%NAMES_JSON%</script>
<script src="__SETTINGS_APP_JS__"></script>
</body></html>
HTML;

        // strtr, one pass: the names are user input and must not be scanned
        // for the placeholders that come after them.
        $html = strtr($html, [
            '%ANAME_ATTR%' => $enc($prefs['assistant_name']),
            '%UNAME_ATTR%' => $enc($prefs['user_name']),
            '%NAMES_JSON%' => $namesJson,
            '%NOTICE%' => $notice,
            '%PASSWORD_SECTION%' => $passwordSection,
            '__THEME_SYNC_JS__' => AssetManager::getUrl('js/app-theme-sync.js', 'os'),
            '__SETTINGS_APP_JS__' => AssetManager::getUrl('js/settings-app.js', 'os'),
        ]);

        return $resource
            ->setContent(CspNonce::stamp($html))
            ->setHeader('Content-Type', 'text/html; charset=utf-8');
    }

    private const PASSWORD_SECTION = <<<'HTML'
<h3>Password</h3>
  <form id="password-form" data-must-change="%MUST_CHANGE%">
    <div class="field">
      <label for="current-password">Current password</label>
      <input id="current-password" type="password" autocomplete="current-password" required>
    </div>
    <div class="field">
      <label for="new-password">New password</label>
      <input id="new-password" type="password" autocomplete="new-password" required>
    </div>
    <div class="field">
      <label for="repeat-password">New password again</label>
      <input id="repeat-password" type="password" autocomplete="new-password" required>
    </div>
    <div class="actions"><button id="change-password" type="submit">Change password</button><span class="hint" id="password-hint"></span></div>
  </form>
HTML;
}
