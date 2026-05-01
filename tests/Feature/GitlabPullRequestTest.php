<?php

use App\Enums\RepoProvider;
use App\Models\Repo;
use App\Models\Workspace;
use App\Services\PullRequests\GitlabPullRequestProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('GitLab provider posts the expected payload to the URL-encoded project path', function () {
    Http::fake([
        'gitlab.com/api/v4/projects/group%2Fsub%2Frepo/merge_requests' => Http::response([
            'web_url' => 'https://gitlab.com/group/sub/repo/-/merge_requests/3',
            'iid' => 3,
            'id' => 99,
        ], 201),
    ]);

    $ws = Workspace::factory()->create();
    $repo = Repo::factory()->for($ws)->create([
        'url' => 'https://gitlab.com/group/sub/repo.git',
        'access_token' => 'glpat',
        'provider' => RepoProvider::Gitlab,
    ]);

    $result = (new GitlabPullRequestProvider)->createPullRequest(
        repo: $repo,
        head: 'specify/feat-x',
        base: 'main',
        title: 'Specify',
        body: 'body',
    );

    expect($result)->toMatchArray([
        'url' => 'https://gitlab.com/group/sub/repo/-/merge_requests/3',
        'number' => 3,
    ]);

    Http::assertSent(function ($req) {
        $body = $req->data();

        return str_contains($req->url(), '/projects/group%2Fsub%2Frepo/merge_requests')
            && $req->hasHeader('PRIVATE-TOKEN', 'glpat')
            && $body['source_branch'] === 'specify/feat-x'
            && $body['target_branch'] === 'main'
            && $body['title'] === 'Specify';
    });
});

test('GitLab provider raises on missing token', function () {
    $ws = Workspace::factory()->create();
    $repo = Repo::factory()->for($ws)->create([
        'access_token' => null,
        'provider' => RepoProvider::Gitlab,
    ]);

    expect(fn () => (new GitlabPullRequestProvider)->createPullRequest($repo, 'h', 'b', 't'))
        ->toThrow(RuntimeException::class, 'access_token');
});

test('Repo::pullRequestProvider returns the GitLab driver for gitlab repos', function () {
    $ws = Workspace::factory()->create();
    $repo = Repo::factory()->for($ws)->create(['provider' => RepoProvider::Gitlab]);

    expect($repo->pullRequestProvider())->toBeInstanceOf(GitlabPullRequestProvider::class);
});
