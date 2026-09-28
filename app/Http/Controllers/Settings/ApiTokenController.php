<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\CreateApiTokenAction;
use App\Actions\RevokeApiTokenAction;
use App\Concerns\InteractsWithCurrentUser;
use App\Enums\ApiAbility;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreApiTokenRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Sanctum\PersonalAccessToken;

class ApiTokenController extends Controller
{
    use InteractsWithCurrentUser;

    public function index(Request $request): Response
    {
        return Inertia::render('settings/Tokens', [
            'tokens' => $this->currentUser()->tokens()
                ->latest()
                ->get()
                ->map(fn (PersonalAccessToken $token): array => $this->present($token))
                ->values()
                ->all(),
            'abilityOptions' => array_map(fn (ApiAbility $ability): array => [
                'value' => $ability->value,
                'description' => $ability->description(),
            ], ApiAbility::cases()),
            'appOptions' => StoreApiTokenRequest::consumerApps(),
            // Session flash, so the plaintext survives exactly one render and
            // is gone on refresh. It is never stored or sent again.
            'createdToken' => $request->session()->get('createdToken'),
        ]);
    }

    public function store(StoreApiTokenRequest $request, CreateApiTokenAction $action): RedirectResponse
    {
        $plainTextToken = $action->handle(
            $this->currentUser(),
            $request->tokenName(),
            $request->abilities(),
            $request->consumerApp(),
        );

        return to_route('api-tokens.index')->with('createdToken', $plainTextToken);
    }

    public function destroy(int $token, RevokeApiTokenAction $action): RedirectResponse
    {
        abort_unless($action->handle($this->currentUser(), $token), 404);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Token revoked.')]);

        return to_route('api-tokens.index');
    }

    /**
     * The app scope is stored as an `app:{slug}` ability, but it says whose
     * token it is rather than what it may do, so the page shows it apart.
     *
     * @return array<string, mixed>
     */
    private function present(PersonalAccessToken $token): array
    {
        $abilities = array_values(array_filter($token->abilities ?? [], 'is_string'));
        $app = collect($abilities)->first(fn (string $ability): bool => str_starts_with($ability, 'app:'));

        return [
            'id' => $token->getKey(),
            'name' => $token->name,
            'abilities' => array_values(array_filter($abilities, fn (string $ability): bool => ! str_starts_with($ability, 'app:'))),
            'app' => $app === null ? null : substr($app, 4),
            'created_at_diff' => $token->created_at?->diffForHumans(),
            'last_used_at_diff' => $token->last_used_at?->diffForHumans(),
        ];
    }
}
