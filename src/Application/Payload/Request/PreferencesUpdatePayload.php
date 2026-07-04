<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Payload\Request;

use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Contract\ValidatablePayloadInterface;
use Semitexa\Core\Http\Response\ResourceResponse;

/**
 * Update OS preferences from the Settings dialog (v0: the assistant's name).
 */
#[AsPublicPayload(
    path: '/os/preferences',
    methods: ['POST'],
    responseWith: ResourceResponse::class,
    consumes: ['application/json'],
    produces: ['application/json'],
)]
final class PreferencesUpdatePayload implements ValidatablePayloadInterface
{
    private string $assistantName = '';

    private string $userName = '';

    private string $themeMode = '';

    private string $timezone = '';

    /**
     * Both fields are optional so the same endpoint serves the onboarding ask
     * (user name only) and the Settings dialog (either/both). The handler
     * applies whichever non-empty fields are present.
     *
     * @return array<string, list<string>>
     */
    public function validate(): array
    {
        return [];
    }

    public function getAssistantName(): string
    {
        return $this->assistantName;
    }

    public function setAssistantName(string $assistantName): void
    {
        $this->assistantName = $assistantName;
    }

    public function getUserName(): string
    {
        return $this->userName;
    }

    public function setUserName(string $userName): void
    {
        $this->userName = $userName;
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }

    public function setTimezone(string $timezone): void
    {
        $this->timezone = $timezone;
    }

    public function getThemeMode(): string
    {
        return $this->themeMode;
    }

    public function setThemeMode(string $themeMode): void
    {
        $this->themeMode = $themeMode;
    }
}
