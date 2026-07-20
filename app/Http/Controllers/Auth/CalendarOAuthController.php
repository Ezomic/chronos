<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Models\ConnectedAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;

class CalendarOAuthController extends Controller
{
    use InteractsWithCurrentUser;

    private const PROVIDERS = ['google', 'microsoft'];

    private const SCOPES = [
        'google' => ['https://www.googleapis.com/auth/calendar.readonly'],
        'microsoft' => ['offline_access', 'https://graph.microsoft.com/Calendars.Read'],
    ];

    public function redirect(string $provider): SymfonyRedirect
    {
        abort_unless(in_array($provider, self::PROVIDERS, true), 404);

        $driver = Socialite::driver($provider);
        abort_unless($driver instanceof AbstractProvider, 500);

        $driver->scopes(self::SCOPES[$provider]);

        if ($provider === 'google') {
            // Google only returns a refresh token with offline access + a
            // forced consent prompt.
            $driver->with(['access_type' => 'offline', 'prompt' => 'consent']);
        }

        return $driver->redirect();
    }

    public function callback(string $provider, Request $request): RedirectResponse
    {
        abort_unless(in_array($provider, self::PROVIDERS, true), 404);

        $oauthUser = Socialite::driver($provider)->user();
        abort_unless($oauthUser instanceof SocialiteUser, 500);

        $account = ConnectedAccount::query()->firstOrNew([
            'user_id' => $this->currentUser()->id,
            'provider' => $provider,
            'email_address' => $oauthUser->getEmail(),
        ]);

        $account->forceFill([
            'display_name' => $oauthUser->getName(),
            'oauth_access_token' => $oauthUser->token,
            // A re-consent without a fresh refresh token must not wipe the one
            // we already hold.
            'oauth_refresh_token' => $oauthUser->refreshToken ?: $account->oauth_refresh_token,
            'oauth_expires_at' => now()->addSeconds($oauthUser->expiresIn),
            'is_active' => true,
            'sync_status' => 'idle',
            'sync_error' => null,
        ])->save();

        return redirect()->route('calendars.edit')
            ->with('status', ucfirst($provider).' account connected.');
    }
}
