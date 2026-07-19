<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\CodeVerification;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\StoreLoginCodeRequest;
use App\Http\Requests\Auth\VerifyLoginCodeRequest;
use App\Mail\LoginCodeMail;
use App\Models\User;
use App\Services\OneTimeCodeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class EmailLoginCodeController extends Controller
{
    public function __construct(private readonly OneTimeCodeService $codes) {}

    public function create(): Response
    {
        return Inertia::render('auth/EmailCode');
    }

    public function store(StoreLoginCodeRequest $request): RedirectResponse
    {
        $email = Str::lower($request->validated('email'));

        // Only existing users receive a code; a new account is created via SSO.
        // The response is identical either way so it doesn't leak who has an account.
        if (User::query()->where('email', $email)->exists()) {
            $code = $this->codes->issue($this->cacheKey($email));

            Mail::to($email)->send(new LoginCodeMail($code));
        }

        $request->session()->put('login-code-email', $email);

        return to_route('login.code.verify');
    }

    public function verify(Request $request): Response|RedirectResponse
    {
        if (! $request->session()->has('login-code-email')) {
            return to_route('login.code.create');
        }

        return Inertia::render('auth/EmailCodeVerify', [
            'email' => $request->session()->get('login-code-email'),
        ]);
    }

    public function authenticate(VerifyLoginCodeRequest $request): RedirectResponse
    {
        $email = $request->session()->get('login-code-email');

        if ($email === null) {
            return to_route('login.code.create');
        }

        $result = $this->codes->verify($this->cacheKey($email), $request->validated('code'));

        if ($result === CodeVerification::Expired) {
            return back()->withErrors(['code' => 'This code has expired. Request a new one.']);
        }

        if ($result === CodeVerification::Incorrect) {
            return back()->withErrors(['code' => 'That code is incorrect.']);
        }

        $user = User::query()->where('email', $email)->firstOrFail();

        // A verified code proves ownership of the address, so confirm the email
        // on first use.
        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        $request->session()->forget('login-code-email');

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    private function cacheKey(string $email): string
    {
        return "login-code:{$email}";
    }
}
