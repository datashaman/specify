<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;

uses(RefreshDatabase::class);

function fakeGithubUser(string $id, string $email, string $name = 'Octo Cat', string $avatar = 'https://avatar/x.png'): SocialiteUser
{
    $user = Mockery::mock(SocialiteUser::class);
    $user->shouldReceive('getId')->andReturn($id);
    $user->shouldReceive('getEmail')->andReturn($email);
    $user->shouldReceive('getName')->andReturn($name);
    $user->shouldReceive('getNickname')->andReturn(null);
    $user->shouldReceive('getAvatar')->andReturn($avatar);

    return $user;
}

test('github callback creates a new user when none matches', function () {
    Socialite::shouldReceive('driver->user')->andReturn(fakeGithubUser('1234', 'octo@example.com'));

    $this->get(route('socialite.callback', 'github'))
        ->assertRedirect(route('dashboard'));

    $user = User::where('github_id', '1234')->firstOrFail();
    expect($user->email)->toBe('octo@example.com')
        ->and($user->avatar_url)->toBe('https://avatar/x.png')
        ->and($user->email_verified_at)->not->toBeNull();
    $this->assertAuthenticatedAs($user);
});

test('github callback links to an existing user matched by email', function () {
    $existing = User::factory()->create(['email' => 'me@example.com']);
    Socialite::shouldReceive('driver->user')->andReturn(fakeGithubUser('999', 'me@example.com'));

    $this->get(route('socialite.callback', 'github'))
        ->assertRedirect(route('dashboard'));

    expect($existing->fresh()->github_id)->toBe('999');
    $this->assertAuthenticatedAs($existing);
});

test('unsupported providers 404', function () {
    $this->get('/auth/twitter/redirect')->assertStatus(404);
});

test('login page shows the GitHub button when configured', function () {
    config(['services.github.client_id' => 'fake']);
    $this->get(route('login'))->assertSee('Continue with GitHub');
});

test('login page hides the GitHub button when not configured', function () {
    config(['services.github.client_id' => null]);
    $this->get(route('login'))->assertDontSee('Continue with GitHub');
});
