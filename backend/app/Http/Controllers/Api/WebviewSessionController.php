<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class WebviewSessionController extends Controller
{
    private const CODE_TTL_SECONDS = 60;

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            abort(401);
        }

        // Agent / scoped tokens must not mint browser sessions.
        if ($user->currentAccessToken() !== null && ! $user->tokenCan('*')) {
            abort(403, 'WebView session requires a full app token.');
        }

        $data = $request->validate([
            'next' => ['sometimes', 'nullable', 'string', 'max:2048'],
        ]);

        $next = $this->sanitizeNextPath($data['next'] ?? null);

        $code = Str::random(64);
        Cache::put($this->cacheKey($code), [
            'user_id' => $user->id,
            'next' => $next,
        ], now()->addSeconds(self::CODE_TTL_SECONDS));

        $query = array_filter([
            'code' => $code,
            'flc_app' => '1',
        ]);

        return response()->json([
            'handoff_url' => url('/auth/webview-handoff').'?'.http_build_query($query),
            'expires_in' => self::CODE_TTL_SECONDS,
        ]);
    }

    public static function cacheKey(string $code): string
    {
        return 'flc_webview_handoff:'.$code;
    }

    /**
     * Only same-origin relative paths (e.g. /home/lookup).
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

        if (str_contains($next, '://')) {
            return null;
        }

        return $next;
    }
}
