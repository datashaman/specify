<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Workspaces\BootstrapPersonalWorkspace;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;

class SocialiteController extends Controller
{
    /** @var array<string, array<int, string>> */
    private const SCOPES = [
        'github' => ['read:user', 'user:email', 'repo', 'admin:repo_hook'],
    ];

    public function redirect(string $provider): SymfonyRedirectResponse
    {
        abort_unless($this->supports($provider), 404);

        return Socialite::driver($provider)
            ->scopes(self::SCOPES[$provider])
            ->redirect();
    }

    public function callback(string $provider): RedirectResponse
    {
        abort_unless($this->supports($provider), 404);

        $oauth = Socialite::driver($provider)->user();

        $user = User::query()
            ->where($provider.'_id', $oauth->getId())
            ->orWhere('email', $oauth->getEmail())
            ->first();

        $tokenAttrs = [
            $provider.'_id' => $oauth->getId(),
            'avatar_url' => $oauth->getAvatar(),
            $provider.'_token' => $oauth->token ?? null,
            $provider.'_refresh_token' => $oauth->refreshToken ?? null,
            $provider.'_token_expires_at' => isset($oauth->expiresIn) ? now()->addSeconds((int) $oauth->expiresIn) : null,
            $provider.'_scopes' => isset($oauth->approvedScopes) ? (array) $oauth->approvedScopes : self::SCOPES[$provider],
        ];

        if ($user === null) {
            $user = User::create(array_merge([
                'name' => $oauth->getName() ?: $oauth->getNickname() ?: 'GitHub user',
                'email' => $oauth->getEmail() ?: $oauth->getId().'@users.noreply.github.com',
                'password' => bcrypt(Str::random(40)),
            ], $tokenAttrs));
            $user->forceFill(['email_verified_at' => now()])->save();
        } else {
            $user->forceFill(array_merge($tokenAttrs, [
                'email_verified_at' => $user->email_verified_at ?? now(),
            ]))->save();
        }

        if ($user->current_team_id === null && $user->teams()->doesntExist()) {
            app(BootstrapPersonalWorkspace::class)->handle($user);
        }

        Auth::login($user, remember: true);

        return redirect()->intended(route('dashboard'));
    }

    private function supports(string $provider): bool
    {
        return array_key_exists($provider, self::SCOPES);
    }
}
