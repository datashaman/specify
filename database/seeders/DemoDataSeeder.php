<?php

namespace Database\Seeders;

use App\Enums\FeatureStatus;
use App\Enums\PlanSource;
use App\Enums\PlanStatus;
use App\Enums\ProjectStatus;
use App\Enums\StoryKind;
use App\Enums\StoryStatus;
use App\Enums\TaskStatus;
use App\Enums\TeamRole;
use App\Models\ApprovalPolicy;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\Project;
use App\Models\Story;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $owner = User::query()->firstOrCreate(
                ['email' => 'test@example.com'],
                [
                    'name' => 'Test User',
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                ],
            );

            $approver = User::query()->firstOrCreate(
                ['email' => 'approver@example.com'],
                [
                    'name' => 'Approver User',
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                ],
            );

            $workspace = Workspace::query()->create([
                'owner_id' => $owner->id,
                'name' => 'Specify Demo Workspace',
                'slug' => 'specify-demo-workspace',
                'description' => 'Seeded demo workspace for exploring Specify.',
            ]);

            $team = Team::query()->create([
                'workspace_id' => $workspace->id,
                'name' => 'Core Product Team',
                'slug' => 'core-product-team',
                'description' => 'Seeded team with owner and approver users.',
            ]);

            $team->addMember($owner, TeamRole::Owner);
            $team->addMember($approver, TeamRole::Admin);

            $project = Project::query()->create([
                'team_id' => $team->id,
                'created_by_id' => $owner->id,
                'name' => 'Specify Demo',
                'slug' => 'specify-demo',
                'description' => 'A demo project showing the reworked story, scenario, and plan structure.',
                'status' => ProjectStatus::Active,
            ]);

            $owner->forceFill([
                'current_team_id' => $team->id,
                'current_project_id' => $project->id,
            ])->save();

            $approver->forceFill([
                'current_team_id' => $team->id,
                'current_project_id' => $project->id,
            ])->save();

            ApprovalPolicy::query()->create([
                'scope_type' => ApprovalPolicy::SCOPE_PROJECT,
                'scope_id' => $project->id,
                'required_approvals' => 1,
                'allow_self_approval' => false,
                'auto_approve' => false,
                'notes' => 'Default seeded project approval policy.',
            ]);

            $peopleWorkspace = Feature::query()->create([
                'project_id' => $project->id,
                'name' => 'People workspace',
                'slug' => 'people-workspace',
                'description' => 'Operator workspace for contact, invitation, and roster administration.',
                'notes' => 'Seeded feature showing a user-story shape with scenarios and a current plan.',
                'status' => FeatureStatus::Active,
            ]);

            $systemStatus = Feature::query()->create([
                'project_id' => $project->id,
                'name' => 'System status',
                'slug' => 'system-status',
                'description' => 'Operator-facing health and probe visibility for integrations and queues.',
                'status' => FeatureStatus::Active,
            ]);

            $storyA = Story::query()->create([
                'feature_id' => $peopleWorkspace->id,
                'created_by_id' => $owner->id,
                'name' => 'Resend invitations from the people workspace',
                'slug' => 'resend-invitations-from-the-people-workspace',
                'kind' => StoryKind::UserStory,
                'actor' => 'festival operations lead',
                'intent' => 'resend invitations to people who have not yet completed access setup',
                'outcome' => 'I can recover stalled onboarding without leaving the workspace',
                'description' => 'Support controlled invite resends for pending or expired invitees from the people workspace.',
                'notes' => 'Seeded example showing framed stories, atomic criteria, scenarios, and a current plan.',
                'status' => StoryStatus::Approved,
                'revision' => 1,
                'position' => 1,
            ]);

            $acA1 = $storyA->acceptanceCriteria()->create([
                'position' => 1,
                'statement' => 'Only people in a resendable invite state can be resent an invitation.',
            ]);
            $acA2 = $storyA->acceptanceCriteria()->create([
                'position' => 2,
                'statement' => 'A successful resend starts a cooldown that prevents repeated sends.',
            ]);

            $storyA->scenarios()->create([
                'acceptance_criterion_id' => $acA1->id,
                'position' => 1,
                'name' => 'Resend available for pending invite',
                'given_text' => 'A person has a pending invitation in the people workspace',
                'when_text' => 'The operator opens the row actions',
                'then_text' => 'A resend action is available',
            ]);
            $storyA->scenarios()->create([
                'acceptance_criterion_id' => $acA2->id,
                'position' => 2,
                'name' => 'Cooldown begins after resend',
                'given_text' => 'A resend succeeds',
                'when_text' => 'The workspace refreshes the invite row',
                'then_text' => 'The resend action is disabled until the cooldown expires',
            ]);

            $planA = Plan::query()->create([
                'story_id' => $storyA->id,
                'version' => 1,
                'name' => 'Initial resend plan',
                'summary' => 'Implement resend eligibility, delivery trigger, and cooldown feedback in the workspace.',
                'design_notes' => "- Keep the action in the people workspace row actions.\n- Keep invite rules at the story/criterion level and implementation detail in tasks.",
                'implementation_notes' => 'Seeded plan record for exploring current-plan navigation and task ownership.',
                'risks' => 'Repeated sends could annoy invitees if cooldown rules are weak.',
                'assumptions' => 'Invite state already exists and resend calls can reuse existing invite plumbing.',
                'source' => PlanSource::Human,
                'source_label' => 'demo-seed',
                'status' => PlanStatus::Approved,
            ]);
            $storyA->forceFill(['current_plan_id' => $planA->id])->save();

            $taskA1 = Task::query()->create([
                'plan_id' => $planA->id,
                'story_id' => $storyA->id,
                'acceptance_criterion_id' => $acA1->id,
                'position' => 1,
                'name' => 'Model resend eligibility in the workspace actions',
                'description' => 'Limit resend controls to records that are currently eligible.',
                'status' => TaskStatus::Done,
            ]);
            $taskA1->subtasks()->createMany([
                [
                    'position' => 1,
                    'name' => 'Identify resendable invite states',
                    'description' => 'Define which invite states show the resend action.',
                    'status' => TaskStatus::Done,
                ],
                [
                    'position' => 2,
                    'name' => 'Render resend affordance in the people workspace',
                    'description' => 'Add the action where operators already manage invites.',
                    'status' => TaskStatus::Done,
                ],
            ]);

            $taskA2 = Task::query()->create([
                'plan_id' => $planA->id,
                'story_id' => $storyA->id,
                'acceptance_criterion_id' => $acA2->id,
                'position' => 2,
                'name' => 'Apply resend cooldown and feedback',
                'description' => 'Persist cooldown timing and surface it to the operator.',
                'status' => TaskStatus::InProgress,
            ]);
            $taskA2->addDependency($taskA1);
            $taskA2->subtasks()->createMany([
                [
                    'position' => 1,
                    'name' => 'Store resend cooldown metadata',
                    'description' => 'Record resend time and derive cooldown expiration.',
                    'status' => TaskStatus::Done,
                ],
                [
                    'position' => 2,
                    'name' => 'Show cooldown state in the workspace row',
                    'description' => 'Display disabled state and remaining cooldown time.',
                    'status' => TaskStatus::Pending,
                ],
            ]);

            $storyB = Story::query()->create([
                'feature_id' => $systemStatus->id,
                'created_by_id' => $owner->id,
                'name' => 'Review service health in one place',
                'slug' => 'review-service-health-in-one-place',
                'kind' => StoryKind::Requirement,
                'description' => 'Operators need a single status page that summarises probes, queues, and integration status before an event day.',
                'status' => StoryStatus::PendingApproval,
                'revision' => 2,
                'position' => 1,
            ]);
            $storyB->acceptanceCriteria()->createMany([
                ['position' => 1, 'statement' => 'The status page shows probe outcomes for critical dependencies.'],
                ['position' => 2, 'statement' => 'The status page highlights failed checks clearly to operators.'],
            ]);
            $storyB->scenarios()->create([
                'position' => 1,
                'name' => 'Probe failures are visible',
                'given_text' => 'One dependency probe is failing',
                'when_text' => 'The operator opens the system status page',
                'then_text' => 'The failed check is clearly highlighted above healthy checks',
            ]);

            $storyC = Story::query()->create([
                'feature_id' => $peopleWorkspace->id,
                'created_by_id' => $owner->id,
                'name' => 'Capture scenario-based invite edge cases',
                'slug' => 'capture-scenario-based-invite-edge-cases',
                'kind' => StoryKind::Enabler,
                'description' => 'Document invite edge cases as scenarios so planning and execution can trace them cleanly.',
                'status' => StoryStatus::Draft,
                'revision' => 1,
                'position' => 2,
            ]);
            $storyC->acceptanceCriteria()->create([
                'position' => 1,
                'statement' => 'Invite edge cases can be recorded as scenarios linked to the relevant rule.',
            ]);
        });
    }
}
