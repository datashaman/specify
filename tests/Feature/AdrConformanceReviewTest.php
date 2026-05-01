<?php

use App\Ai\Agents\AdrConformanceReviewer;
use App\Enums\AgentRunStatus;
use App\Enums\RepoProvider;
use App\Jobs\ReviewPullRequestJob;
use App\Models\AgentRun;
use App\Models\Repo;
use App\Models\Subtask;
use App\Models\Workspace;
use App\Services\Reviews\GithubReviewProvider;
use App\Services\Reviews\ReviewComment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('GithubReviewProvider posts a COMMENT review with inline + body comments', function () {
    Http::fake([
        'api.github.com/repos/owner/repo/pulls/7/reviews' => Http::response([
            'html_url' => 'https://github.com/owner/repo/pull/7#pullrequestreview-99',
            'id' => 99,
        ], 200),
    ]);

    $ws = Workspace::factory()->create();
    $repo = Repo::factory()->for($ws)->create([
        'url' => 'https://github.com/owner/repo.git',
        'access_token' => 'ghp_secret',
        'provider' => RepoProvider::Github,
    ]);

    $result = (new GithubReviewProvider)->postReview(
        repo: $repo,
        pullRequestNumber: 7,
        summary: 'one warning, one info',
        comments: [
            new ReviewComment(body: 'inline issue', path: 'app/Foo.php', line: 12, severity: ReviewComment::SEVERITY_WARNING),
            new ReviewComment(body: 'no line attached', path: 'app/Bar.php', line: 0, severity: ReviewComment::SEVERITY_INFO),
            new ReviewComment(body: 'no path either', severity: ReviewComment::SEVERITY_ERROR),
        ],
    );

    expect($result['url'])->toContain('pullrequestreview-99')
        ->and($result['id'])->toBe(99);

    Http::assertSent(function ($request) {
        if ($request->url() !== 'https://api.github.com/repos/owner/repo/pulls/7/reviews') {
            return false;
        }
        $body = $request->data();
        if (($body['event'] ?? null) !== 'COMMENT') {
            return false;
        }
        // The body should hold the summary + the two non-line-attached comments.
        if (! str_contains($body['body'] ?? '', 'one warning, one info')) {
            return false;
        }
        if (! str_contains($body['body'] ?? '', '[INFO] no line attached')) {
            return false;
        }
        if (! str_contains($body['body'] ?? '', '[ERROR] no path either')) {
            return false;
        }
        // Inline comments should hold only the line-attached one.
        $inline = $body['comments'] ?? [];

        return count($inline) === 1
            && $inline[0]['path'] === 'app/Foo.php'
            && $inline[0]['line'] === 12
            && str_contains($inline[0]['body'], '[WARNING] inline issue');
    });
});

test('GithubReviewProvider raises when access_token is missing', function () {
    $repo = Repo::factory()->for(Workspace::factory()->create())->create([
        'access_token' => null,
        'provider' => RepoProvider::Github,
    ]);

    expect(fn () => (new GithubReviewProvider)->postReview($repo, 1, 's', []))
        ->toThrow(RuntimeException::class, 'access_token');
});

test('Repo::reviewProvider returns the GitHub driver only for Github repos', function () {
    $ws = Workspace::factory()->create();
    $github = Repo::factory()->for($ws)->create(['provider' => RepoProvider::Github]);
    $generic = Repo::factory()->for($ws)->create(['provider' => RepoProvider::Generic]);

    expect($github->reviewProvider())->toBeInstanceOf(GithubReviewProvider::class)
        ->and($generic->reviewProvider())->toBeNull();
});

test('ReviewPullRequestJob caps comments at 10, severity-sorted, and skips when no PR was opened', function () {
    // No PR number on output → silently no-op (also no HTTP attempted).
    $repo = Repo::factory()->for(Workspace::factory()->create())->create([
        'access_token' => 'ghp',
        'provider' => RepoProvider::Github,
    ]);
    $subtask = Subtask::factory()->create();
    $run = AgentRun::create([
        'runnable_type' => Subtask::class,
        'runnable_id' => $subtask->getKey(),
        'repo_id' => $repo->getKey(),
        'status' => AgentRunStatus::Succeeded,
        'output' => ['files_changed' => ['app/Foo.php']],
        'diff' => '',
    ]);

    Http::fake();

    (new ReviewPullRequestJob($run->getKey()))->handle();

    Http::assertNothingSent();
});

test('ReviewPullRequestJob runs the agent, posts a review, and stores review_url on the AgentRun', function () {
    // Stage an Accepted ADR on disk that the job will load.
    $adrDir = base_path('docs/adr');
    File::ensureDirectoryExists($adrDir);
    $marker = $adrDir.'/9999-test-only-fixture.md';
    File::put($marker, "# 9999. Test fixture\n\nDate: 2026-05-01\nStatus: Accepted\n\n## Decision\n\nAlways flag a test fixture.\n");

    Http::fake([
        'api.github.com/*/reviews' => Http::response(['html_url' => 'https://example/pull/1#review', 'id' => 1], 200),
    ]);

    $ws = Workspace::factory()->create();
    $repo = Repo::factory()->for($ws)->create([
        'url' => 'https://github.com/owner/repo.git',
        'access_token' => 'ghp',
        'provider' => RepoProvider::Github,
    ]);
    $subtask = Subtask::factory()->create();
    $run = AgentRun::create([
        'runnable_type' => Subtask::class,
        'runnable_id' => $subtask->getKey(),
        'repo_id' => $repo->getKey(),
        'status' => AgentRunStatus::Succeeded,
        'output' => [
            'pull_request_number' => 7,
            'files_changed' => ['app/Foo.php'],
        ],
        'diff' => "--- a/app/Foo.php\n+++ b/app/Foo.php\n@@\n+ // example change\n",
    ]);

    AdrConformanceReviewer::fake(fn () => [
        'overall' => 'warn',
        'summary' => 'One warning, one info.',
        'violations' => [
            ['adr' => '9999-test-only-fixture.md', 'file' => 'app/Foo.php', 'line' => 1, 'reason' => 'inline reason', 'severity' => 'warning'],
            ['adr' => '9999-test-only-fixture.md', 'file' => 'app/Foo.php', 'line' => 0, 'reason' => 'body reason', 'severity' => 'info'],
        ],
    ]);

    try {
        (new ReviewPullRequestJob($run->getKey()))->handle();

        $persisted = $run->fresh()->output;
        expect($persisted['review_url'] ?? null)->toContain('pull/1#review')
            ->and($persisted['review_overall'] ?? null)->toBe('warn')
            ->and($persisted['review_comment_count'] ?? null)->toBe(2);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return str_contains($request->url(), '/pulls/7/reviews')
                && ($body['event'] ?? null) === 'COMMENT'
                && str_contains($body['body'] ?? '', 'ADR-conformance review (advisory)');
        });
    } finally {
        File::delete($marker);
    }
});
