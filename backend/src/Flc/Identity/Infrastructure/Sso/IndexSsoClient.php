<?php

namespace Flc\Identity\Infrastructure\Sso;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class IndexSsoClient
{
    /**
     * @return array{sub: int, email: string, name: string, avatar: string|null}
     */
    public function exchangeAuthorizationCode(string $clientId, string $redirectUri, string $code): array
    {
        $secret = $this->clientSecret($clientId);

        $response = Http::asForm()
            ->acceptJson()
            ->post($this->tokenUrl(), [
                'grant_type' => 'authorization_code',
                'client_id' => $clientId,
                'client_secret' => $secret,
                'code' => $code,
                'redirect_uri' => $redirectUri,
            ]);

        if ($response->failed()) {
            throw new RequestException($response);
        }

        /** @var array<string, mixed> $payload */
        $payload = $response->json();

        $email = isset($payload['email']) && is_string($payload['email'])
            ? strtolower(trim($payload['email']))
            : '';

        if ($email === '') {
            throw new \RuntimeException('SSO response missing email');
        }

        return [
            'sub' => (int) ($payload['sub'] ?? 0),
            'email' => $email,
            'name' => is_string($payload['name'] ?? null) ? $payload['name'] : Str::before($email, '@'),
            'avatar' => is_string($payload['avatar'] ?? null) ? $payload['avatar'] : null,
        ];
    }

    public function authorizeUrl(string $clientId, string $redirectUri, ?string $state = null): string
    {
        $state ??= Str::random(40);
        session(['sso.oauth_state' => $state]);

        return $this->idpUrl().'/auth/sso/authorize?'.http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'state' => $state,
        ]);
    }

    public function validateState(?string $state): bool
    {
        $expected = session()->pull('sso.oauth_state');

        return is_string($expected) && is_string($state) && hash_equals($expected, $state);
    }

    private function clientSecret(string $clientId): string
    {
        $secret = config('sso.secret');

        if (! is_string($secret) || $secret === '') {
            throw new \RuntimeException('SSO_SECRET is not configured');
        }

        return $secret;
    }

    private function idpUrl(): string
    {
        return rtrim((string) config('sso.idp_url'), '/');
    }

    private function tokenUrl(): string
    {
        return $this->idpUrl().'/api/auth/sso/token';
    }
}
