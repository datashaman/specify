<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;

class SocialiteController extends Controller
{
    public function redirect(string $provider): SymfonyRedirectResponse
    {
        abort_unless($this->supports($provider), 404);

        return Socialite::driver($provider)->redirect();
    }

    public function callback(string $provider): RedirectResponse
    {
        abort_unless($this->supports($provider), 404);

        $oauth = Socialite::driver($provider)->user();

        $user = User::query()
            ->where($provider.'_id', $oauth->getId())
            ->orWhere('email', $oauth->getEmail())
            ->first();

        if ($user === null) {
            $user = User::create([
                'name' => $oauth->getName() ?: $oauth->getNickname() ?: 'GitHub user',
                'email' => $oauth->getEmail() ?: $oauth->getId().'@users.noreply.github.com',
                'password' => bcrypt(Str::random(40)),
                $provider.'_id' => $oauth->getId(),
                'avatar_url' => $oauth->getAvatar(),
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();
        } else {
            $user->forceFill([
                $provider.'_id' => $oauth->getId(),
                'avatar_url' => $oauth->getAvatar(),
                'email_verified_at' => $user->email_verified_at ?? now(),
            ])->save();
        }

        Auth::login($user, remember: true);

        return redirect()->intended(route('dashboard'));
    }

    private function supports(string $provider): bool
    {
        return in_array($provider, ['github'], true);
    }
}
