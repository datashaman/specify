<?php

use App\Enums\RepoProvider;
use App\Models\Repo;
use App\Models\Workspace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

test('latestCommit fetches the head sha for a github repo with a token', function () {
    Cache::flush();
    Http::fake([
        'api.github.com/repos/datashaman/specify/commits/*' => Http::response([
            'sha' => 'abcdef1234567890',
            'commit' => ['message' => 'initial commit'],
            'html_url' => 'https://github.com/datashaman/specify/commit/abcdef1234567890',
        ]),
    ]);

    $repo = Repo::factory()->for(Workspace::factory())->create([
        'provider' => RepoProvider::Github,
        'url' => 'https://github.com/datashaman/specify.git',
        'default_branch' => 'main',
        'access_token' => 'gho_test',
    ]);

    expect($repo->latestCommit())->toMatchArray([
        'sha' => 'abcdef1234567890',
        'short' => 'abcdef1',
    ]);
});

test('latestCommit returns null without a token', function () {
    Cache::flush();
    $repo = Repo::factory()->for(Workspace::factory())->create([
        'provider' => RepoProvider::Github,
        'url' => 'https://github.com/datashaman/specify.git',
        'access_token' => null,
    ]);

    expect($repo->latestCommit())->toBeNull();
});

test('latestCommit returns null when the API rejects the token', function () {
    Cache::flush();
    Http::fake([
        'api.github.com/*' => Http::response(['message' => 'Bad credentials'], 401),
    ]);

    $repo = Repo::factory()->for(Workspace::factory())->create([
        'provider' => RepoProvider::Github,
        'url' => 'https://github.com/datashaman/specify.git',
        'access_token' => 'gho_bad',
    ]);

    expect($repo->latestCommit())->toBeNull();
});
