<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Flc\Identity\Infrastructure\Sso\IndexSsoClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminAuthController extends Controller
{
    private const CLIENT_ID = 'flc-admin';

    public function __construct(private readonly IndexSsoClient $sso) {}

    public function showLogin(): View|RedirectResponse
    {
        $email = session('admin_email');
        if ($email && in_array(strtolower($email), config('flc.admin_emails', []), true)) {
            return redirect()->route('admin.dashboard');
        }

        return view('admin.login');
    }

    public function redirectSso(): RedirectResponse
    {
        return redirect()->away(
            $this->sso->authorizeUrl(self::CLIENT_ID, route('admin.auth.sso.callback'))
        );
    }

    public function callbackSso(Request $request): RedirectResponse
    {
        $state = (string) $request->query('state', '');
        $code = (string) $request->query('code', '');

        if ($code === '' || ! $this->sso->validateState($state)) {
            return redirect()->route('admin.login')
                ->with('error', 'Phiên SSO không hợp lệ.');
        }

        try {
            $claims = $this->sso->exchangeAuthorizationCode(
                self::CLIENT_ID,
                route('admin.auth.sso.callback'),
                $code,
            );
        } catch (\Throwable) {
            return redirect()->route('admin.login')
                ->with('error', 'Đăng nhập SSO thất bại.');
        }

        $email = strtolower(trim($claims['email']));
        $admins = config('flc.admin_emails', []);

        if ($email === '' || ! in_array($email, $admins, true)) {
            return redirect()->route('admin.login')
                ->with('error', 'Email này không có quyền truy cập admin.');
        }

        session([
            'admin_email' => $email,
            'admin_name' => $claims['name'] ?: $email,
        ]);

        return redirect()->route('admin.dashboard');
    }

    public function logout(): RedirectResponse
    {
        session()->forget(['admin_email', 'admin_name']);

        return redirect()->route('admin.login')
            ->with('success', 'Đã đăng xuất.');
    }
}
