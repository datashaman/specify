<?php

use App\Enums\RepoProvider;
use App\Models\Repo;
use App\Models\Workspace;
use App\Services\PullRequests\BitbucketPullRequestProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('Bitbucket provider posts to repositories/{ws}/{repo}/pullrequests with bearer auth', function () {
    Http::fake([
        'api.bitbucket.org/2.0/repositories/space/repo/pullrequests' => Http::response([
            'id' => 5,
            'links' => ['html' => ['href' => 'https://bitbucket.org/space/repo/pull-requests/5']],
        ], 201),
    ]);

    $ws = Workspace::factory()->create();
    $repo = Repo::factory()->for($ws)->create([
        'url' => 'https://bitbucket.org/space/repo.git',
        'access_token' => 'bb-token',
        'provider' => RepoProvider::Bitbucket,
    ]);

    $result = (new BitbucketPullRequestProvider)->createPullRequest(
        repo: $repo,
        head: 'specify/feat-x',
        base: 'main',
        title: 'Specify',
        body: 'body',
    );

    expect($result)->toMatchArray([
        'url' => 'https://bitbucket.org/space/repo/pull-requests/5',
        'number' => 5,
    ]);

    Http::assertSent(function ($req) {
        $body = $req->data();

        return str_contains($req->url(), '/repositories/space/repo/pullrequests')
            && $req->hasHeader('Authorization', 'Bearer bb-token')
            && $body['source']['branch']['name'] === 'specify/feat-x'
            && $body['destination']['branch']['name'] === 'main'
            && $body['title'] === 'Specify';
    });
});

test('Bitbucket provider raises on missing token', function () {
    $ws = Workspace::factory()->create();
    $repo = Repo::factory()->for($ws)->create([
        'access_token' => null,
        'provider' => RepoProvider::Bitbucket,
    ]);

    expect(fn () => (new BitbucketPullRequestProvider)->createPullRequest($repo, 'h', 'b', 't'))
        ->toThrow(RuntimeException::class, 'access_token');
});

test('Repo::pullRequestProvider returns the Bitbucket driver for bitbucket repos', function () {
    $ws = Workspace::factory()->create();
    $repo = Repo::factory()->for($ws)->create(['provider' => RepoProvider::Bitbucket]);

    expect($repo->pullRequestProvider())->toBeInstanceOf(BitbucketPullRequestProvider::class);
});
