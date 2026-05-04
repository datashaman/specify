<?php

use App\Models\ApprovalPolicy;
use App\Services\Approvals\ApprovalPolicyResolver;

test('approval policy resolver falls back to the default policy', function () {
    $story = makeStory();

    $policy = app(ApprovalPolicyResolver::class)->forStory($story);

    expect($policy->exists)->toBeFalse()
        ->and($policy->required_approvals)->toBe(0)
        ->and($policy->allow_self_approval)->toBeFalse()
        ->and($policy->auto_approve)->toBeFalse();
});

test('approval policy resolver uses workspace project story precedence', function () {
    $story = makeStory();
    $project = $story->feature->project;
    $workspace = $project->team->workspace;

    ApprovalPolicy::create([
        'scope_type' => ApprovalPolicy::SCOPE_WORKSPACE,
        'scope_id' => $workspace->id,
        'required_approvals' => 3,
    ]);

    expect(app(ApprovalPolicyResolver::class)->forStory($story)->required_approvals)->toBe(3);

    ApprovalPolicy::create([
        'scope_type' => ApprovalPolicy::SCOPE_PROJECT,
        'scope_id' => $project->id,
        'required_approvals' => 2,
    ]);

    expect(app(ApprovalPolicyResolver::class)->forStory($story)->required_approvals)->toBe(2);

    ApprovalPolicy::create([
        'scope_type' => ApprovalPolicy::SCOPE_STORY,
        'scope_id' => $story->id,
        'required_approvals' => 1,
    ]);

    expect(app(ApprovalPolicyResolver::class)->forStory($story)->required_approvals)->toBe(1);
});

test('approval policy resolver short-circuits before loading broader scope relations', function () {
    $story = makeStory();

    ApprovalPolicy::create([
        'scope_type' => ApprovalPolicy::SCOPE_STORY,
        'scope_id' => $story->id,
        'required_approvals' => 1,
    ]);

    $policy = app(ApprovalPolicyResolver::class)->forStory($story);

    expect($policy->required_approvals)->toBe(1)
        ->and($story->relationLoaded('feature'))->toBeFalse();
});

test('approval policy resolver reads workspace id without hydrating team workspace relations', function () {
    $story = makeStory()->load('feature.project');
    $project = $story->feature->project;
    $workspace = $project->team->workspace;
    $project->unsetRelation('team');

    ApprovalPolicy::create([
        'scope_type' => ApprovalPolicy::SCOPE_WORKSPACE,
        'scope_id' => $workspace->id,
        'required_approvals' => 3,
    ]);

    $policy = app(ApprovalPolicyResolver::class)->forStory($story);

    expect($policy->required_approvals)->toBe(3)
        ->and($project->relationLoaded('team'))->toBeFalse();
});
