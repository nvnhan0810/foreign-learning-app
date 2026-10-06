<?php

namespace Flc\Identity\Infrastructure\Sso;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Validates an Apple Reader (Index) Sanctum bearer by calling IdP /api/v1/me.
 */
final class IndexMeClient
{
    /**
     * @return array{email: string, name: string, avatar: string|null}|null
     */
    public function fetchMe(string $bearerToken): ?array
    {
        $token = trim($bearerToken);
        if ($token === '') {
            return null;
        }

        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout(8)
            ->get($this->meUrl());

        if ($response->failed()) {
            return null;
        }

        /** @var array<string, mixed> $payload */
        $payload = $response->json();
        $user = is_array($payload['user'] ?? null) ? $payload['user'] : $payload;

        $email = isset($user['email']) && is_string($user['email'])
            ? strtolower(trim($user['email']))
            : '';

        if ($email === '') {
            return null;
        }

        return [
            'email' => $email,
            'name' => is_string($user['name'] ?? null) ? $user['name'] : Str::before($email, '@'),
            'avatar' => is_string($user['avatar'] ?? null) ? $user['avatar'] : null,
        ];
    }

    private function meUrl(): string
    {
        return rtrim((string) config('sso.idp_url'), '/').'/api/v1/me';
    }
}
