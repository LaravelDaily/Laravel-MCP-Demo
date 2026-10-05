<?php

namespace Database\Seeders;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\WorkspaceRole;
use App\Models\Comment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class TeamBoardDemoSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the deterministic local-demo dataset. Due dates are calculated
     * relative to the seeding time so the review scenarios stay useful.
     */
    public function run(): void
    {
        $acme = Workspace::create(['name' => 'Acme', 'slug' => 'acme']);
        $sandbox = Workspace::create(['name' => 'Sandbox', 'slug' => 'sandbox']);
        $globex = Workspace::create(['name' => 'Globex', 'slug' => 'globex']);

        $alice = User::factory()->create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $bob = User::factory()->create(['name' => 'Bob', 'email' => 'bob@example.com']);
        $charlie = User::factory()->create(['name' => 'Charlie', 'email' => 'charlie@example.com']);
        $dana = User::factory()->create(['name' => 'Dana', 'email' => 'dana@example.com']);
        $eve = User::factory()->create(['name' => 'Eve', 'email' => 'eve@example.com']);

        $this->member($acme, $alice, WorkspaceRole::Owner);
        $this->member($acme, $bob, WorkspaceRole::Member);
        $this->member($acme, $charlie, WorkspaceRole::Viewer);
        $this->member($acme, $dana, WorkspaceRole::Owner);
        $this->member($sandbox, $dana, WorkspaceRole::Viewer);
        $this->member($globex, $eve, WorkspaceRole::Owner);

        $this->seedAcme($acme, $alice, $bob);
        $this->seedSandbox($sandbox, $dana);
        $this->seedGlobex($globex, $eve);
    }

    /**
     * Acme carries the full demo: every status and priority, assignments,
     * comments, and the overdue scenarios used in the review demonstration.
     */
    protected function seedAcme(Workspace $acme, User $alice, User $bob): void
    {
        $website = $this->project($acme, [
            'name' => 'Website Redesign',
            'description' => 'Refresh the marketing site with the new brand.',
            'instructions' => "Project conventions:\n- Acceptance criteria are required before work starts on a task.\n- Bug reports must include reproduction steps.\n- Final assets are stored in the shared drive.",
        ]);

        $billing = $this->project($acme, [
            'name' => 'Billing Improvements',
            'description' => 'Make billing easier to understand and support.',
            'instructions' => "Project conventions:\n- Acceptance criteria are required before work starts on a task.\n- Bug reports must include reproduction steps and the affected customer segment.",
        ]);

        $pricingPage = $this->task($website, [
            'assignee_id' => $alice->id,
            'title' => 'Redesign pricing page',
            'description' => 'Simplify the three pricing tiers and highlight the annual discount.',
            'status' => TaskStatus::InProgress,
            'priority' => TaskPriority::High,
            'due_date' => now()->addDays(5),
        ]);

        $this->task($website, [
            'assignee_id' => $bob->id,
            'title' => 'Fix mobile navigation',
            'description' => 'The menu overlaps the logo on screens narrower than 400px.',
            'status' => TaskStatus::Todo,
            'priority' => TaskPriority::Medium,
            'due_date' => now()->addDays(10),
        ]);

        $this->task($website, [
            'title' => 'Prepare launch checklist',
            'status' => TaskStatus::Todo,
            'priority' => TaskPriority::Low,
            'due_date' => now()->addDays(14),
        ]);

        $stripeWebhook = $this->task($billing, [
            'assignee_id' => $alice->id,
            'title' => 'Investigate failed Stripe webhook',
            'description' => 'Roughly one in twenty webhook deliveries fails with a signature mismatch.',
            'status' => TaskStatus::InProgress,
            'priority' => TaskPriority::High,
            'due_date' => now()->subDays(3),
        ]);

        $this->task($billing, [
            'assignee_id' => $bob->id,
            'title' => 'Add invoice download',
            'description' => 'Let members download a PDF invoice from the billing screen.',
            'status' => TaskStatus::Todo,
            'priority' => TaskPriority::Medium,
            'due_date' => now()->addDays(7),
        ]);

        $this->task($billing, [
            'assignee_id' => $bob->id,
            'title' => 'Improve subscription cancellation flow',
            'description' => 'Reduce the number of confirmation steps from five to two.',
            'status' => TaskStatus::Done,
            'priority' => TaskPriority::Low,
            'due_date' => now()->subDays(7),
        ]);

        $this->comment($pricingPage, $alice, 'Copy for the new pricing tiers is ready for review.');
        $this->comment($pricingPage, $bob, 'I can start the responsive layouts once the copy is approved.');
        $this->comment($stripeWebhook, $alice, 'The mismatches started after the key rotation, so I will compare payloads against the Stripe dashboard.');
        $this->comment($stripeWebhook, $bob, 'I can pull the failed delivery logs tomorrow morning.');
        $this->comment($stripeWebhook, $alice, 'Follow-up from support: two customers asked about duplicate charges.');
    }

    protected function seedSandbox(Workspace $sandbox, User $dana): void
    {
        $experiments = $this->project($sandbox, [
            'name' => 'Sandbox Experiments',
            'description' => 'Throwaway projects for trying integrations.',
            'instructions' => 'This workspace is a playground for testing integrations; nothing here is production data.',
        ]);

        $this->task($experiments, [
            'assignee_id' => $dana->id,
            'title' => 'Test webhook integration',
            'description' => 'Point the webhook receiver at the local tunnel and confirm deliveries arrive.',
            'status' => TaskStatus::Todo,
            'priority' => TaskPriority::Medium,
            'due_date' => now()->addDays(3),
        ]);

        $this->task($experiments, [
            'title' => 'Prototype dark mode',
            'description' => 'Check how the dashboard components look with a dark palette.',
            'status' => TaskStatus::Done,
            'priority' => TaskPriority::Low,
        ]);
    }

    protected function seedGlobex(Workspace $globex, User $eve): void
    {
        $payroll = $this->project($globex, [
            'name' => 'Globex Payroll Platform',
            'description' => 'Consolidate contractor and employee payments.',
        ]);

        $this->task($payroll, [
            'assignee_id' => $eve->id,
            'title' => 'Migrate payroll data to the new provider',
            'description' => 'Move two years of payment records without downtime.',
            'status' => TaskStatus::InProgress,
            'priority' => TaskPriority::High,
            'due_date' => now()->addDays(21),
        ]);

        $this->task($payroll, [
            'title' => 'Audit contractor payments',
            'description' => 'Compare the last four quarters against the invoices received.',
            'status' => TaskStatus::Todo,
            'priority' => TaskPriority::Medium,
            'due_date' => now()->addDays(30),
        ]);

        $brand = $this->project($globex, [
            'name' => 'Globex Brand Refresh',
            'description' => 'Update the logo and stationery.',
        ]);

        $this->task($brand, [
            'assignee_id' => $eve->id,
            'title' => 'Refresh brand guidelines',
            'status' => TaskStatus::Todo,
            'priority' => TaskPriority::Low,
        ]);
    }

    protected function member(Workspace $workspace, User $user, WorkspaceRole $role): void
    {
        $workspace->memberships()->create([
            'user_id' => $user->id,
            'role' => $role,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function project(Workspace $workspace, array $attributes): Project
    {
        return $workspace->projects()->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function task(Project $project, array $attributes): Task
    {
        return $project->tasks()->create($attributes);
    }

    protected function comment(Task $task, User $author, string $body): Comment
    {
        return $task->comments()->create([
            'user_id' => $author->id,
            'body' => $body,
        ]);
    }
}
