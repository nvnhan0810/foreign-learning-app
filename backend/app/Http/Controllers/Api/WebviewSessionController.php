<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Flc\Identity\Application\Query\IsEmailAllowed;
use Flc\Identity\Infrastructure\Sso\IndexMeClient;
use Flc\Shared\Application\QueryBus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class WebviewSessionController extends Controller
{
    private const CODE_TTL_SECONDS = 60;

    public function __construct(
        private readonly QueryBus $queries,
        private readonly IndexMeClient $indexMe,
    ) {}

    /**
     * Issue a one-time ticket that establishes a Laravel web session in a WebView.
     *
     * Accepts:
     * - Foreign Sanctum bearer (FLC mobile / extension)
     * - Index Reader Sanctum bearer (Apple Reader) via IdP /api/v1/me
     */
    public function store(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            abort(401);
        }

        $data = $request->validate([
            'next' => ['sometimes', 'nullable', 'string', 'max:2048'],
        ]);

        $next = $this->sanitizeNextPath($data['next'] ?? null);

        $ticket = Str::random(64);
        Cache::put($this->cacheKey($ticket), [
            'user_id' => $user->id,
            'next' => $next,
        ], now()->addSeconds(self::CODE_TTL_SECONDS));

        $handoffQuery = array_filter([
            'code' => $ticket,
            'flc_app' => '1',
        ]);

        $consumeQuery = array_filter([
            'ticket' => $ticket,
            'next' => $next,
        ]);

        return response()->json([
            'ticket' => $ticket,
            'expires_in' => self::CODE_TTL_SECONDS,
            'handoff_url' => url('/auth/webview-handoff').'?'.http_build_query($handoffQuery),
            'consume_url' => url('/auth/bridge/consume').'?'.http_build_query($consumeQuery),
        ]);
    }

    /**
     * Consume a one-time ticket and log the user into a web session.
     *
     * Accepts `code` (FLC mobile) or `ticket` (Apple Reader bridge).
     * Optional `next` query overrides the path stored when the ticket was issued.
     */
    public function handoff(Request $request): RedirectResponse
    {
        $ticket = trim((string) $request->query('ticket', $request->query('code', '')));

        if ($ticket === '') {
            return redirect()->route('user.login')
                ->with('error', 'Missing auth ticket.');
        }

        $payload = Cache::pull($this->cacheKey($ticket));

        if (! is_array($payload) || empty($payload['user_id'])) {
            return redirect()->route('user.login')
                ->with('error', 'Auth ticket is invalid or expired.');
        }

        $user = User::query()->find($payload['user_id']);

        if ($user === null) {
            return redirect()->route('user.login')
                ->with('error', 'User not found.');
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        $nextFromQuery = $this->sanitizeNextPath(
            is_string($request->query('next')) ? $request->query('next') : null
        );
        $nextFromTicket = is_string($payload['next'] ?? null)
            ? $this->sanitizeNextPath($payload['next'])
            : null;

        $next = $nextFromQuery ?? $nextFromTicket ?? '/home/lookup';

        return redirect()->to($next);
    }

    public static function cacheKey(string $code): string
    {
        return 'flc_webview_handoff:'.$code;
    }

    private function resolveUser(Request $request): ?User
    {
        $bearer = $request->bearerToken();
        if ($bearer === null || $bearer === '') {
            return null;
        }

        $local = PersonalAccessToken::findToken($bearer);
        if ($local !== null) {
            $tokenable = $local->tokenable;
            if (! $tokenable instanceof User) {
                return null;
            }

            // Agent / scoped tokens must not mint browser sessions.
            if (! $local->can('*')) {
                abort(403, 'WebView session requires a full app token.');
            }

            return $tokenable;
        }

        $claims = $this->indexMe->fetchMe($bearer);
        if ($claims === null) {
            return null;
        }

        if (! $this->queries->ask(new IsEmailAllowed($claims['email']))) {
            abort(403, 'This email is not allowed. Contact an admin to add it to the allowlist.');
        }

        return User::query()->updateOrCreate(
            ['email' => $claims['email']],
            [
                'name' => $claims['name'] !== '' ? $claims['name'] : Str::before($claims['email'], '@'),
                'email_verified_at' => now(),
                'password' => Hash::make(Str::random(64)),
            ]
        );
    }

    /**
     * Only same-origin relative app paths (e.g. /lookup?q=word, /home/lookup).
     */
    private function sanitizeNextPath(?string $next): ?string
    {
        if ($next === null) {
            return null;
        }

        $next = trim($next);
        if ($next === '' || ! str_starts_with($next, '/') || str_starts_with($next, '//')) {
            return null;
        }

        if (str_contains($next, '://') || str_contains($next, '\\')) {
            return null;
        }

        $path = parse_url($next, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            return null;
        }

        $allowedPrefixes = ['/lookup', '/home', '/listening', '/auth/webview-handoff'];
        $allowed = false;
        foreach ($allowedPrefixes as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                $allowed = true;
                break;
            }
        }

        if (! $allowed) {
            return null;
        }

        return $next;
    }
}
