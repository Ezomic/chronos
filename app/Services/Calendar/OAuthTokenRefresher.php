<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use App\Models\ConnectedAccount;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Keeps a connected account's OAuth access token fresh. Access tokens for both
 * Google and Microsoft expire (~1 hour); we exchange the stored refresh token
 * for a new access token whenever it's expired or about to expire.
 */
class OAuthTokenRefresher
{
    public function freshAccessToken(ConnectedAccount $account): string
    {
        if (! $account->tokenIsExpired() && $account->oauth_access_token) {
            return $account->oauth_access_token;
        }

        return match ($account->provider) {
            ConnectedAccount::PROVIDER_GOOGLE => $this->refreshGoogle($account),
            ConnectedAccount::PROVIDER_MICROSOFT => $this->refreshMicrosoft($account),
            default => throw new RuntimeException("Account {$account->id} does not use OAuth."),
        };
    }

    protected function refreshGoogle(ConnectedAccount $account): string
    {
        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'refresh_token' => $account->oauth_refresh_token,
            'grant_type' => 'refresh_token',
        ]);

        if ($response->failed()) {
            $account->update(['sync_status' => 'error', 'sync_error' => 'Google token refresh failed: '.$response->body()]);
            throw new RuntimeException('Google token refresh failed for account '.$account->id);
        }

        $data = $response->json();
        $accessToken = is_array($data) && is_string($data['access_token'] ?? null) ? $data['access_token'] : null;
        $expiresIn = is_array($data) && is_numeric($data['expires_in'] ?? null) ? (int) $data['expires_in'] : 3600;

        if ($accessToken === null) {
            throw new RuntimeException('Google token refresh returned no access token for account '.$account->id);
        }

        $account->update([
            'oauth_access_token' => $accessToken,
            'oauth_expires_at' => now()->addSeconds($expiresIn),
        ]);

        return $accessToken;
    }

    protected function refreshMicrosoft(ConnectedAccount $account): string
    {
        $response = Http::asForm()->post('https://login.microsoftonline.com/common/oauth2/v2.0/token', [
            'client_id' => config('services.microsoft.client_id'),
            'client_secret' => config('services.microsoft.client_secret'),
            'refresh_token' => $account->oauth_refresh_token,
            'grant_type' => 'refresh_token',
            'scope' => 'offline_access https://graph.microsoft.com/Calendars.Read',
        ]);

        if ($response->failed()) {
            $account->update(['sync_status' => 'error', 'sync_error' => 'Microsoft token refresh failed: '.$response->body()]);
            throw new RuntimeException('Microsoft token refresh failed for account '.$account->id);
        }

        $data = $response->json();
        $accessToken = is_array($data) && is_string($data['access_token'] ?? null) ? $data['access_token'] : null;
        $refreshToken = is_array($data) && is_string($data['refresh_token'] ?? null) ? $data['refresh_token'] : $account->oauth_refresh_token;
        $expiresIn = is_array($data) && is_numeric($data['expires_in'] ?? null) ? (int) $data['expires_in'] : 3600;

        if ($accessToken === null) {
            throw new RuntimeException('Microsoft token refresh returned no access token for account '.$account->id);
        }

        $account->update([
            'oauth_access_token' => $accessToken,
            // Microsoft rotates refresh tokens on most requests.
            'oauth_refresh_token' => $refreshToken,
            'oauth_expires_at' => now()->addSeconds($expiresIn),
        ]);

        return $accessToken;
    }
}
