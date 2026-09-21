<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $email = strtolower((string) $request->session()->get('admin_email', ''));
        $admins = config('flc.admin_emails', []);

        if ($email === '' || ! in_array($email, $admins, true)) {
            return redirect()->route('admin.login')
                ->with('error', 'Bạn cần đăng nhập admin.');
        }

        return $next($request);
    }
}
