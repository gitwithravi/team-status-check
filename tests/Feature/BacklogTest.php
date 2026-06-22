<?php

namespace Tests\Feature;

use App\Models\BacklogTask;
use App\Models\DailyTask;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BacklogTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_all_backlog_tasks(): void
    {
        $admin = User::factory()->admin()->create();
        $manager = User::factory()->teamManager()->create();
        $team1 = Team::query()->create(['name' => 'Team One', 'manager_id' => $manager->id]);
        $team2 = Team::query()->create(['name' => 'Team Two', 'manager_id' => $manager->id]);

        BacklogTask::create(['team_id' => $team1->id, 'project_name' => 'Proj1', 'title' => 'Task 1']);
        BacklogTask::create(['team_id' => $team2->id, 'project_name' => 'Proj2', 'title' => 'Task 2']);

        $response = $this->actingAs($admin)->getJson('/backlog');

        $response->assertOk()
            ->assertJsonCount(2, 'backlogs')
            ->assertJsonCount(2, 'teams');
    }

    public function test_manager_can_view_only_their_managed_teams_backlog_tasks(): void
    {
        $manager1 = User::factory()->teamManager()->create();
        $manager2 = User::factory()->teamManager()->create();

        $team1 = Team::query()->create(['name' => 'Team One', 'manager_id' => $manager1->id]);
        $team2 = Team::query()->create(['name' => 'Team Two', 'manager_id' => $manager2->id]);

        BacklogTask::create(['team_id' => $team1->id, 'project_name' => 'Proj1', 'title' => 'Task 1']);
        BacklogTask::create(['team_id' => $team2->id, 'project_name' => 'Proj2', 'title' => 'Task 2']);

        $response = $this->actingAs($manager1)->getJson('/backlog');

        $response->assertOk()
            ->assertJsonCount(1, 'backlogs')
            ->assertJsonPath('backlogs.0.title', 'Task 1')
            ->assertJsonCount(1, 'teams')
            ->assertJsonPath('teams.0.id', $team1->id);
    }

    public function test_member_can_view_only_their_teams_backlog_tasks(): void
    {
        $manager = User::factory()->teamManager()->create();
        $member = User::factory()->member()->create();

        $team1 = Team::query()->create(['name' => 'Team One', 'manager_id' => $manager->id]);
        $team2 = Team::query()->create(['name' => 'Team Two', 'manager_id' => $manager->id]);

        $team1->members()->attach($member);

        BacklogTask::create(['team_id' => $team1->id, 'project_name' => 'Proj1', 'title' => 'Task 1']);
        BacklogTask::create(['team_id' => $team2->id, 'project_name' => 'Proj2', 'title' => 'Task 2']);

        $response = $this->actingAs($member)->getJson('/backlog');

        $response->assertOk()
            ->assertJsonCount(1, 'backlogs')
            ->assertJsonPath('backlogs.0.title', 'Task 1')
            ->assertJsonCount(0, 'teams'); // Members don't get the teams list (it's for dropdowns in creation)
    }

    public function test_assigned_backlog_tasks_do_not_show_in_shared_backlog(): void
    {
        $manager = User::factory()->teamManager()->create();
        $member = User::factory()->member()->create();
        $team = Team::query()->create(['name' => 'Team One', 'manager_id' => $manager->id]);
        $team->members()->attach($member);

        BacklogTask::create(['team_id' => $team->id, 'project_name' => 'Proj1', 'title' => 'Shared task']);
        BacklogTask::create([
            'team_id' => $team->id,
            'assigned_user_id' => $member->id,
            'project_name' => 'Proj1',
            'title' => 'Assigned task',
        ]);

        $this->actingAs($member)->getJson('/backlog')
            ->assertOk()
            ->assertJsonCount(1, 'backlogs')
            ->assertJsonPath('backlogs.0.title', 'Shared task');
    }

    public function test_admin_and_manager_can_create_backlog_tasks(): void
    {
        $admin = User::factory()->admin()->create();
        $manager = User::factory()->teamManager()->create();
        $team = Team::query()->create(['name' => 'Team One', 'manager_id' => $manager->id]);

        // Admin create
        $response = $this->actingAs($admin)->postJson('/backlog', [
            'project_name' => 'Proj Admin',
            'title' => 'Title Admin',
            'description' => 'Desc Admin',
            'team_id' => $team->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('backlog.title', 'Title Admin');

        $this->assertDatabaseHas('backlog_tasks', [
            'title' => 'Title Admin',
            'team_id' => $team->id,
        ]);

        // Manager create for managed team
        $response = $this->actingAs($manager)->postJson('/backlog', [
            'project_name' => 'Proj Manager',
            'title' => 'Title Manager',
            'description' => 'Desc Manager',
            'team_id' => $team->id,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('backlog_tasks', [
            'title' => 'Title Manager',
            'team_id' => $team->id,
        ]);
    }

    public function test_admin_can_create_assigned_backlog_task_for_team_member(): void
    {
        $admin = User::factory()->admin()->create();
        $manager = User::factory()->teamManager()->create();
        $member = User::factory()->member()->create();
        $team = Team::query()->create(['name' => 'Team One', 'manager_id' => $manager->id]);
        $team->members()->attach($member);

        $response = $this->actingAs($admin)->postJson('/backlog', [
            'project_name' => 'Proj Admin',
            'title' => 'Assigned Title',
            'description' => 'Assigned Desc',
            'team_id' => $team->id,
            'assigned_user_id' => $member->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('backlog.assigned_user.id', $member->id);

        $this->assertDatabaseHas('backlog_tasks', [
            'title' => 'Assigned Title',
            'team_id' => $team->id,
            'assigned_user_id' => $member->id,
        ]);
    }

    public function test_cannot_assign_backlog_task_to_member_outside_team(): void
    {
        $admin = User::factory()->admin()->create();
        $manager = User::factory()->teamManager()->create();
        $member = User::factory()->member()->create();
        $team = Team::query()->create(['name' => 'Team One', 'manager_id' => $manager->id]);

        $this->actingAs($admin)->postJson('/backlog', [
            'project_name' => 'Proj Admin',
            'title' => 'Assigned Title',
            'team_id' => $team->id,
            'assigned_user_id' => $member->id,
        ])->assertStatus(422);
    }

    public function test_manager_cannot_create_backlog_task_for_unmanaged_team(): void
    {
        $manager1 = User::factory()->teamManager()->create();
        $manager2 = User::factory()->teamManager()->create();
        $team = Team::query()->create(['name' => 'Team Two', 'manager_id' => $manager2->id]);

        $response = $this->actingAs($manager1)->postJson('/backlog', [
            'project_name' => 'Proj Error',
            'title' => 'Title Error',
            'team_id' => $team->id,
        ]);

        $response->assertForbidden();
    }

    public function test_member_cannot_create_backlog_task(): void
    {
        $member = User::factory()->member()->create();
        $manager = User::factory()->teamManager()->create();
        $team = Team::query()->create(['name' => 'Team One', 'manager_id' => $manager->id]);

        $response = $this->actingAs($member)->postJson('/backlog', [
            'project_name' => 'Proj Member',
            'title' => 'Title Member',
            'team_id' => $team->id,
        ]);

        $response->assertForbidden();
    }

    public function test_member_can_move_backlog_task_to_daily_tasks(): void
    {
        $manager = User::factory()->teamManager()->create();
        $member = User::factory()->member()->create();
        $team = Team::query()->create(['name' => 'Team One', 'manager_id' => $manager->id]);
        $team->members()->attach($member);

        $backlog = BacklogTask::create([
            'team_id' => $team->id,
            'project_name' => 'Operations',
            'title' => 'Setup CI',
            'description' => 'Use GitHub Actions',
        ]);

        $response = $this->actingAs($member)->postJson("/backlog/{$backlog->id}/move");

        $response->assertOk()
            ->assertJsonPath('message', 'Task moved to today.');

        // Verify DailyTask is created
        $this->assertDatabaseHas('daily_tasks', [
            'user_id' => $member->id,
            'project_name' => 'Operations',
            'title' => 'Setup CI',
            'notes' => 'Use GitHub Actions',
            'status' => 'planned',
        ]);

        // Verify BacklogTask is deleted
        $this->assertDatabaseMissing('backlog_tasks', [
            'id' => $backlog->id,
        ]);
    }

    public function test_assigned_member_can_view_their_personal_backlog(): void
    {
        $manager = User::factory()->teamManager()->create();
        $member = User::factory()->member()->create();
        $otherMember = User::factory()->member()->create();
        $team = Team::query()->create(['name' => 'Team One', 'manager_id' => $manager->id]);
        $team->members()->attach([$member->id, $otherMember->id]);

        BacklogTask::create([
            'team_id' => $team->id,
            'assigned_user_id' => $member->id,
            'project_name' => 'Operations',
            'title' => 'My task',
        ]);

        BacklogTask::create([
            'team_id' => $team->id,
            'assigned_user_id' => $otherMember->id,
            'project_name' => 'Operations',
            'title' => 'Other task',
        ]);

        $this->actingAs($member)->getJson('/my-backlog')
            ->assertOk()
            ->assertJsonCount(1, 'backlogs')
            ->assertJsonPath('backlogs.0.title', 'My task');
    }

    public function test_member_can_move_task_assigned_to_them(): void
    {
        $manager = User::factory()->teamManager()->create();
        $member = User::factory()->member()->create();
        $team = Team::query()->create(['name' => 'Team One', 'manager_id' => $manager->id]);
        $team->members()->attach($member);

        $backlog = BacklogTask::create([
            'team_id' => $team->id,
            'assigned_user_id' => $member->id,
            'project_name' => 'Operations',
            'title' => 'Assigned task',
        ]);

        $this->actingAs($member)->postJson("/backlog/{$backlog->id}/move")
            ->assertOk();

        $this->assertDatabaseHas('daily_tasks', [
            'user_id' => $member->id,
            'title' => 'Assigned task',
            'backlog_assigned_user_id' => $member->id,
        ]);
    }

    public function test_member_cannot_move_task_assigned_to_another_member(): void
    {
        $manager = User::factory()->teamManager()->create();
        $member1 = User::factory()->member()->create();
        $member2 = User::factory()->member()->create();
        $team = Team::query()->create(['name' => 'Team One', 'manager_id' => $manager->id]);
        $team->members()->attach([$member1->id, $member2->id]);

        $backlog = BacklogTask::create([
            'team_id' => $team->id,
            'assigned_user_id' => $member1->id,
            'project_name' => 'Operations',
            'title' => 'Assigned task',
        ]);

        $this->actingAs($member2)->postJson("/backlog/{$backlog->id}/move")
            ->assertForbidden();

        $this->assertDatabaseHas('backlog_tasks', ['id' => $backlog->id]);
    }

    public function test_member_cannot_move_backlog_task_if_not_in_team(): void
    {
        $manager = User::factory()->teamManager()->create();
        $member = User::factory()->member()->create();
        $team = Team::query()->create(['name' => 'Team One', 'manager_id' => $manager->id]);
        // member is NOT attached to team

        $backlog = BacklogTask::create([
            'team_id' => $team->id,
            'project_name' => 'Operations',
            'title' => 'Setup CI',
        ]);

        $response = $this->actingAs($member)->postJson("/backlog/{$backlog->id}/move");

        $response->assertForbidden();
        $this->assertDatabaseHas('backlog_tasks', ['id' => $backlog->id]);
    }

    public function test_admin_and_manager_can_delete_backlog_tasks(): void
    {
        $admin = User::factory()->admin()->create();
        $manager = User::factory()->teamManager()->create();
        $team = Team::query()->create(['name' => 'Team One', 'manager_id' => $manager->id]);

        $backlog1 = BacklogTask::create(['team_id' => $team->id, 'project_name' => 'P1', 'title' => 'T1']);
        $backlog2 = BacklogTask::create(['team_id' => $team->id, 'project_name' => 'P1', 'title' => 'T2']);

        // Admin delete
        $this->actingAs($admin)->deleteJson("/backlog/{$backlog1->id}")
            ->assertOk();
        $this->assertDatabaseMissing('backlog_tasks', ['id' => $backlog1->id]);

        // Manager delete for managed team
        $this->actingAs($manager)->deleteJson("/backlog/{$backlog2->id}")
            ->assertOk();
        $this->assertDatabaseMissing('backlog_tasks', ['id' => $backlog2->id]);
    }

    public function test_manager_cannot_delete_backlog_task_of_unmanaged_team(): void
    {
        $manager1 = User::factory()->teamManager()->create();
        $manager2 = User::factory()->teamManager()->create();
        $team = Team::query()->create(['name' => 'Team Two', 'manager_id' => $manager2->id]);
        $backlog = BacklogTask::create(['team_id' => $team->id, 'project_name' => 'P1', 'title' => 'T1']);

        $response = $this->actingAs($manager1)->deleteJson("/backlog/{$backlog->id}");

        $response->assertForbidden();
        $this->assertDatabaseHas('backlog_tasks', ['id' => $backlog->id]);
    }

    public function test_member_cannot_delete_moved_backlog_task_directly(): void
    {
        $manager = User::factory()->teamManager()->create();
        $member = User::factory()->member()->create();
        $team = Team::query()->create(['name' => 'Team One', 'manager_id' => $manager->id]);
        $team->members()->attach($member);

        $backlog = BacklogTask::create([
            'team_id' => $team->id,
            'project_name' => 'Operations',
            'title' => 'Setup CI',
        ]);

        // Move to today
        $this->actingAs($member)->postJson("/backlog/{$backlog->id}/move")->assertOk();

        $dailyTask = DailyTask::query()->where('user_id', $member->id)->firstOrFail();
        $this->assertNotNull($dailyTask->team_id);

        // Attempt direct delete -> should be blocked (422)
        $response = $this->actingAs($member)->deleteJson("/tasks/{$dailyTask->id}");
        $response->assertStatus(422);
        $this->assertDatabaseHas('daily_tasks', ['id' => $dailyTask->id]);
    }

    public function test_member_can_return_daily_task_to_backlog(): void
    {
        $manager = User::factory()->teamManager()->create();
        $member = User::factory()->member()->create();
        $team = Team::query()->create(['name' => 'Team One', 'manager_id' => $manager->id]);
        $team->members()->attach($member);

        $backlog = BacklogTask::create([
            'team_id' => $team->id,
            'project_name' => 'Operations',
            'title' => 'Setup CI',
            'description' => 'Optional notes',
        ]);

        // Move to today
        $this->actingAs($member)->postJson("/backlog/{$backlog->id}/move")->assertOk();
        $dailyTask = DailyTask::query()->where('user_id', $member->id)->firstOrFail();

        // Return to backlog
        $response = $this->actingAs($member)->postJson("/tasks/{$dailyTask->id}/backlog");
        $response->assertOk()
            ->assertJsonPath('message', 'Task returned to backlog.');

        // Verify daily task is deleted
        $this->assertDatabaseMissing('daily_tasks', ['id' => $dailyTask->id]);

        // Verify backlog task is recreated
        $this->assertDatabaseHas('backlog_tasks', [
            'team_id' => $team->id,
            'project_name' => 'Operations',
            'title' => 'Setup CI',
            'description' => 'Optional notes',
        ]);
    }

    public function test_return_to_backlog_preserves_assignment(): void
    {
        $manager = User::factory()->teamManager()->create();
        $member = User::factory()->member()->create();
        $team = Team::query()->create(['name' => 'Team One', 'manager_id' => $manager->id]);
        $team->members()->attach($member);

        $backlog = BacklogTask::create([
            'team_id' => $team->id,
            'assigned_user_id' => $member->id,
            'project_name' => 'Operations',
            'title' => 'Assigned task',
        ]);

        $this->actingAs($member)->postJson("/backlog/{$backlog->id}/move")->assertOk();
        $dailyTask = DailyTask::query()->where('user_id', $member->id)->firstOrFail();

        $this->actingAs($member)->postJson("/tasks/{$dailyTask->id}/backlog")
            ->assertOk();

        $this->assertDatabaseHas('backlog_tasks', [
            'team_id' => $team->id,
            'title' => 'Assigned task',
            'assigned_user_id' => $member->id,
        ]);

        $this->actingAs($member)->getJson('/backlog')
            ->assertOk()
            ->assertJsonMissing(['title' => 'Assigned task']);

        $this->actingAs($member)->getJson('/my-backlog')
            ->assertOk()
            ->assertJsonPath('backlogs.0.title', 'Assigned task');
    }

    public function test_member_cannot_return_daily_task_to_backlog_if_not_in_team(): void
    {
        $manager = User::factory()->teamManager()->create();
        $member1 = User::factory()->member()->create();
        $member2 = User::factory()->member()->create();
        $team = Team::query()->create(['name' => 'Team One', 'manager_id' => $manager->id]);
        
        $team->members()->attach($member1); // member1 is in team, member2 is not

        $backlog = BacklogTask::create([
            'team_id' => $team->id,
            'project_name' => 'Operations',
            'title' => 'Setup CI',
        ]);

        // Move to today (done by member1)
        $this->actingAs($member1)->postJson("/backlog/{$backlog->id}/move")->assertOk();
        $dailyTask = DailyTask::query()->where('user_id', $member1->id)->firstOrFail();

        // Attempt return to backlog by member2 -> should be forbidden (403)
        $response = $this->actingAs($member2)->postJson("/tasks/{$dailyTask->id}/backlog");
        $response->assertForbidden();

        $this->assertDatabaseHas('daily_tasks', ['id' => $dailyTask->id]);
        $this->assertDatabaseMissing('backlog_tasks', ['project_name' => 'Operations']);
    }
}
