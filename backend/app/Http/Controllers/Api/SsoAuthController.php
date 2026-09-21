<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Flc\Identity\Application\Query\IsEmailAllowed;
use Flc\Identity\Infrastructure\Sso\IndexSsoClient;
use Flc\Shared\Application\QueryBus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class SsoAuthController extends Controller
{
    private const CLIENT_MOBILE = 'flc-mobile';

    public function __construct(
        private readonly QueryBus $queries,
        private readonly IndexSsoClient $sso,
    ) {}

    public function redirect(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'redirect_uri' => ['required', 'string', 'max:2048'],
        ]);

        if (! $this->isValidMobileRedirect($data['redirect_uri'])) {
            abort(400, 'redirect_uri không hợp lệ.');
        }

        $state = Str::random(48);
        Cache::put($this->stateKey($state), [
            'redirect_uri' => $data['redirect_uri'],
        ], now()->addMinutes(10));

        $idp = rtrim((string) config('sso.idp_url'), '/');
        $authorize = $idp.'/auth/sso/authorize?'.http_build_query([
            'client_id' => self::CLIENT_MOBILE,
            'redirect_uri' => $data['redirect_uri'],
            'response_type' => 'code',
            'state' => $state,
        ]);

        return redirect()->away($authorize);
    }

    public function exchange(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:128'],
            'redirect_uri' => ['required', 'string', 'max:2048'],
            'state' => ['required', 'string', 'max:128'],
        ]);

        if (! $this->isValidMobileRedirect($data['redirect_uri'])) {
            abort(400, 'redirect_uri không hợp lệ.');
        }

        $payload = Cache::pull($this->stateKey($data['state']));
        if (! is_array($payload) || ($payload['redirect_uri'] ?? '') !== $data['redirect_uri']) {
            abort(401, 'Phiên đăng nhập không hợp lệ hoặc đã hết hạn.');
        }

        try {
            $claims = $this->sso->exchangeAuthorizationCode(
                self::CLIENT_MOBILE,
                $data['redirect_uri'],
                $data['code'],
            );
        } catch (\Throwable) {
            abort(401, 'Đăng nhập SSO thất bại.');
        }

        $email = $claims['email'];

        if (! $this->queries->ask(new IsEmailAllowed($email))) {
            abort(403, 'Email chưa được phép sử dụng. Liên hệ quản trị để thêm vào allowlist.');
        }

        $user = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $claims['name'] ?: Str::before($email, '@'),
                'email_verified_at' => now(),
                'password' => Hash::make(Str::random(64)),
            ]
        );

        $token = $user->createToken('flc-mobile')->plainTextToken;

        return response()->json([
            'token' => $token,
            'email' => $user->email,
            'name' => $user->name,
        ]);
    }

    private function stateKey(string $state): string
    {
        return 'flc_sso_state:'.$state;
    }

    private function isValidMobileRedirect(string $uri): bool
    {
        $parsed = parse_url($uri);

        if (! $parsed || empty($parsed['scheme']) || empty($parsed['host'])) {
            return false;
        }

        $host = strtolower($parsed['host']);

        if ($parsed['scheme'] === 'https' && str_ends_with($host, '.chromiumapp.org')) {
            return true;
        }

        if ($parsed['scheme'] === 'flc' && ($parsed['host'] ?? '') === 'oauth-callback') {
            return true;
        }

        if (in_array($host, ['localhost', '127.0.0.1'], true)) {
            return in_array($parsed['scheme'], ['http', 'https'], true);
        }

        return false;
    }

    private function redirectWithError(string $redirectUri, string $message): RedirectResponse
    {
        if ($this->isValidMobileRedirect($redirectUri)) {
            return redirect($redirectUri.'?'.http_build_query([
                'error' => $message,
            ]));
        }

        abort(403, $message);
    }
}
