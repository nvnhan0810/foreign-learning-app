<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class DetectFlcMobileApp
{
    public function handle(Request $request, Closure $next): Response
    {
        $isApp = $request->header('X-FLC-App') === '1'
            || str_contains($request->userAgent() ?? '', 'FLCApp/')
            || $request->cookie('flc_app') === '1'
            || $request->query('flc_app') === '1';

        View::share('isFlcApp', $isApp);
        $request->attributes->set('flc_app', $isApp);

        $theme = $this->normalizeTheme(
            $request->query('flc_theme')
                ?? $request->header('X-FLC-Theme')
                ?? $request->cookie('flc_theme')
        );
        View::share('flcTheme', $theme);
        if ($theme !== null) {
            $request->attributes->set('flc_theme', $theme);
        }

        $response = $next($request);

        if ($isApp && $request->cookie('flc_app') !== '1') {
            $response = $response->withCookie(
                cookie('flc_app', '1', 525600, '/', null, $request->isSecure(), true, false, 'Lax')
            );
        }

        if ($theme !== null && $request->cookie('flc_theme') !== $theme) {
            $response = $response->withCookie(
                cookie('flc_theme', $theme, 525600, '/', null, $request->isSecure(), false, false, 'Lax')
            );
        }

        return $response;
    }

    private function normalizeTheme(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = strtolower(trim($value));

        return in_array($value, ['light', 'dark', 'system'], true) ? $value : null;
    }
}
