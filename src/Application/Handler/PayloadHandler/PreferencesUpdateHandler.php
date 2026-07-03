<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Os\Application\Payload\Request\PreferencesUpdatePayload;
use Semitexa\Os\Application\Service\OsPreferences;

/**
 * Apply a preferences change from the Settings dialog (v0: assistant name).
 * Returns the applied preferences so the client can reflect them immediately.
 */
#[AsPayloadHandler(payload: PreferencesUpdatePayload::class, resource: ResourceResponse::class)]
final class PreferencesUpdateHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected OsPreferences $prefs;

    public function handle(PreferencesUpdatePayload $payload, ResourceResponse $resource): ResourceResponse
    {
        $assistant = trim($payload->getAssistantName());
        $user = trim($payload->getUserName());
        $themeMode = trim($payload->getThemeMode());
        $applied = false;
        $error = null;

        try {
            if ($assistant !== '') {
                $this->prefs->setAssistantName($assistant);
                $applied = true;
            }
            if ($user !== '') {
                $this->prefs->setUserName($user);
                $applied = true;
            }
            if ($themeMode !== '') {
                $this->prefs->setThemeMode($themeMode);
                $applied = true;
            }
        } catch (\InvalidArgumentException $e) {
            $error = $e->getMessage();
        }

        if (!$applied) {
            $body = ['ok' => false, 'error' => $error ?? 'Nothing to update.'];
        } else {
            $body = ['ok' => true] + $this->prefs->all();
        }

        return $resource
            ->setContent((string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            ->setHeader('Content-Type', 'application/json');
    }
}
