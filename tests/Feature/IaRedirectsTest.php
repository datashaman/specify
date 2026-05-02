<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('/inbox 301-redirects to /triage', function () {
    $user = User::factory()->create();
    $this->actingAs($user)
        ->get('/inbox')
        ->assertStatus(301)
        ->assertRedirect('/triage');
});

test('/events 301-redirects to /activity', function () {
    $user = User::factory()->create();
    $this->actingAs($user)
        ->get('/events')
        ->assertStatus(301)
        ->assertRedirect('/activity');
});

test('triage and activity routes resolve', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    expect(route('triage'))->toEndWith('/triage');
    expect(route('activity.index'))->toEndWith('/activity');
});
