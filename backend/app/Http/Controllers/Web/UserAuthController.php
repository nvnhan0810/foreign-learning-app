<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Api\WebviewSessionController;
use App\Http\Controllers\Controller;
use App\Models\User;
use Flc\Identity\Application\Query\IsEmailAllowed;
use Flc\Identity\Infrastructure\Sso\IndexSsoClient;
use Flc\Shared\Application\QueryBus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class UserAuthController extends Controller
{
    private const CLIENT_ID = 'flc-web';

    public function __construct(
        private readonly QueryBus $queries,
        private readonly IndexSsoClient $sso,
    ) {}

    public function showLogin(): Response|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('user.home.lookup');
        }

        return Inertia::render('Auth/Login', [
            'googleUrl' => route('user.auth.sso'),
        ]);
    }

    public function redirectSso(): RedirectResponse
    {
        return redirect()->away(
            $this->sso->authorizeUrl(self::CLIENT_ID, route('user.auth.sso.callback'))
        );
    }

    public function callbackSso(Request $request): RedirectResponse
    {
        $state = (string) $request->query('state', '');
        $code = (string) $request->query('code', '');

        if ($code === '' || ! $this->sso->validateState($state)) {
            return redirect()->route('user.login')
                ->with('error', 'SSO session invalid or expired.');
        }

        try {
            $claims = $this->sso->exchangeAuthorizationCode(
                self::CLIENT_ID,
                route('user.auth.sso.callback'),
                $code,
            );
        } catch (\Throwable) {
            return redirect()->route('user.login')
                ->with('error', 'SSO sign-in failed.');
        }

        $email = $claims['email'];

        if ($email === '') {
            return redirect()->route('user.login')
                ->with('error', 'Google account has no email.');
        }

        if (! $this->queries->ask(new IsEmailAllowed($email))) {
            return redirect()->route('user.login')
                ->with('error', 'This email is not allowed. Contact an admin to add it to the allowlist.');
        }

        $user = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $claims['name'] ?: Str::before($email, '@'),
                'email_verified_at' => now(),
                'password' => Hash::make(Str::random(64)),
            ]
        );

        Auth::login($user, remember: true);

        return redirect()->route('user.home.lookup');
    }

    public function logout(): RedirectResponse
    {
        Auth::logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();

        $isApp = request()->attributes->get('flc_app') === true
            || request()->cookie('flc_app') === '1'
            || str_contains(request()->userAgent() ?? '', 'FLCApp/');

        if ($isApp) {
            return redirect()->route('user.login', ['flc_logout' => '1'])
                ->with('success', 'Signed out.');
        }

        return redirect()->route('user.login')
            ->with('success', 'Signed out.');
    }

    public function webviewHandoff(Request $request): RedirectResponse
    {
        return app(WebviewSessionController::class)->handoff($request);
    }
}
