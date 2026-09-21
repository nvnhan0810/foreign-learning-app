<?php

namespace Flc\Notification\Application;

interface PushNotifier
{
    public function isConfigured(): bool;

    /**
     * @param  array<string, string>  $data
     */
    public function sendToToken(string $token, string $title, string $body, array $data = []): bool;
}
