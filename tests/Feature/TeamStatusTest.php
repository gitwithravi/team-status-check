<?php

namespace Tests\Feature;

use App\Models\DailyTask;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TeamStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_json_requests_return_unauthorized(): void
    {
        $this->getJson('/session')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_admin_can_create_a_team_member(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->postJson('/admin/members', [
            'name' => 'Ravi Kumar',
            'email' => 'ravi@example.com',
            'password' => 'password123',
            'active' => true,
        ]);

        $response->assertCreated()->assertJsonPath('member.email', 'ravi@example.com');
        $this->assertDatabaseHas('users', [
            'email' => 'ravi@example.com',
            'role' => 'member',
            'active' => true,
        ]);
    }

    public function test_admin_can_create_a_team_manager(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->postJson('/admin/members', [
            'name' => 'Manager User',
            'email' => 'manager@example.com',
            'password' => 'password123',
            'role' => User::ROLE_TEAM_MANAGER,
            'active' => true,
        ]);

        $response->assertCreated()->assertJsonPath('user.role', User::ROLE_TEAM_MANAGER);
        $this->assertDatabaseHas('users', [
            'email' => 'manager@example.com',
            'role' => User::ROLE_TEAM_MANAGER,
            'active' => true,
        ]);
    }

    public function test_member_can_login_and_create_multiple_today_tasks(): void
    {
        $member = User::factory()->member()->create([
            'email' => 'member@example.com',
            'password' => Hash::make('password123'),
        ]);

        $this->postJson('/login', [
            'email' => 'member@example.com',
            'password' => 'password123',
        ])->assertOk()->assertJsonPath('user.id', $member->id);

        $this->postJson('/tasks', [
            'title' => 'Prepare deployment',
            'status' => 'planned',
        ])->assertCreated();

        $this->postJson('/tasks', [
            'title' => 'Review blockers',
            'status' => 'blocked',
            'notes' => 'Waiting for credentials.',
        ])->assertCreated();

        $this->assertDatabaseCount('daily_tasks', 2);
    }

    public function test_inactive_member_cannot_login(): void
    {
        Auth::logout();
        $this->flushSession();

        User::query()->create([
            'name' => 'Inactive Member',
            'email' => 'inactive@example.com',
            'password' => Hash::make('password123'),
            'role' => 'member',
            'active' => false,
        ]);

        $response = $this->postJson('/login', [
            'email' => 'inactive@example.com',
            'password' => 'password123',
        ]);

        $this->assertSame(422, $response->getStatusCode(), $response->getContent());
    }

    public function test_users_can_update_their_own_password(): void
    {
        $admin = User::factory()->admin()->create([
            'email' => 'admin@example.com',
            'password' => Hash::make('old-password'),
        ]);

        $this->actingAs($admin)->putJson('/password', [
            'current_password' => 'old-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertOk()->assertJsonPath('message', 'Password updated.');

        $this->assertTrue(Hash::check('new-password', $admin->fresh()->password));
    }

    public function test_users_must_provide_current_password_to_update_password(): void
    {
        $member = User::factory()->member()->create([
            'password' => Hash::make('old-password'),
        ]);

        $this->actingAs($member)->putJson('/password', [
            'current_password' => 'wrong-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $this->assertTrue(Hash::check('old-password', $member->fresh()->password));
    }

    public function test_member_cannot_modify_past_day_tasks(): void
    {
        $member = User::factory()->member()->create();
        $task = DailyTask::query()->create([
            'user_id' => $member->id,
            'work_date' => today(config('app.timezone'))->subDay()->toDateString(),
            'title' => 'Old task',
            'status' => 'planned',
        ]);

        $this->actingAs($member)->putJson("/tasks/{$task->id}", [
            'title' => 'Changed',
            'status' => 'done',
        ])->assertUnprocessable();

        $this->assertDatabaseHas('daily_tasks', [
            'id' => $task->id,
            'title' => 'Old task',
        ]);
    }

    public function test_member_can_view_their_task_history(): void
    {
        $member = User::factory()->member()->create();
        $other = User::factory()->member()->create();
        $yesterday = today(config('app.timezone'))->subDay()->toDateString();
        $today = today(config('app.timezone'))->toDateString();

        DailyTask::query()->create([
            'user_id' => $member->id,
            'work_date' => $yesterday,
            'title' => 'Yesterday task',
            'status' => 'done',
        ]);

        DailyTask::query()->create([
            'user_id' => $member->id,
            'work_date' => $today,
            'title' => 'Today task',
            'status' => 'planned',
        ]);

        DailyTask::query()->create([
            'user_id' => $other->id,
            'work_date' => $today,
            'title' => 'Other member task',
            'status' => 'blocked',
        ]);

        $this->actingAs($member)->getJson('/tasks/history')
            ->assertOk()
            ->assertJsonCount(2, 'tasks')
            ->assertJsonPath('tasks.0.title', 'Today task')
            ->assertJsonPath('tasks.1.title', 'Yesterday task');

        $this->actingAs($member)->getJson("/tasks/history?date={$yesterday}")
            ->assertOk()
            ->assertJsonCount(1, 'tasks')
            ->assertJsonPath('tasks.0.title', 'Yesterday task');
    }

    public function test_member_cannot_access_another_members_task(): void
    {
        $member = User::factory()->member()->create();
        $other = User::factory()->member()->create();
        $task = DailyTask::query()->create([
            'user_id' => $other->id,
            'work_date' => today(config('app.timezone'))->toDateString(),
            'title' => 'Private task',
            'status' => 'planned',
        ]);

        $this->actingAs($member)->putJson("/tasks/{$task->id}", [
            'title' => 'Changed',
            'status' => 'done',
        ])->assertForbidden();
    }

    public function test_admin_can_filter_dashboard(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->member()->create(['name' => 'Visible Member']);
        $other = User::factory()->member()->create(['name' => 'Hidden Member']);

        DailyTask::query()->create([
            'user_id' => $member->id,
            'work_date' => '2026-06-11',
            'title' => 'Blocked item',
            'status' => 'blocked',
        ]);
        DailyTask::query()->create([
            'user_id' => $other->id,
            'work_date' => '2026-06-11',
            'title' => 'Done item',
            'status' => 'done',
        ]);

        $response = $this->actingAs($admin)->getJson("/admin/dashboard?date=2026-06-11&member_id={$member->id}&status=blocked");

        $response->assertOk()
            ->assertJsonCount(1, 'members')
            ->assertJsonPath('members.0.name', 'Visible Member')
            ->assertJsonPath('members.0.tasks.0.title', 'Blocked item');
    }

    public function test_admin_can_create_and_update_a_team(): void
    {
        $admin = User::factory()->admin()->create();
        $manager = User::factory()->teamManager()->create();
        $member = User::factory()->member()->create();
        $otherMember = User::factory()->member()->create();

        $response = $this->actingAs($admin)->postJson('/admin/teams', [
            'name' => 'Product',
            'manager_id' => $manager->id,
            'member_ids' => [$member->id],
        ]);

        $response->assertCreated()
            ->assertJsonPath('team.name', 'Product')
            ->assertJsonPath('team.manager.id', $manager->id)
            ->assertJsonCount(1, 'team.members');

        $team = Team::query()->firstOrFail();

        $this->actingAs($admin)->putJson("/admin/teams/{$team->id}", [
            'name' => 'Platform',
            'manager_id' => $manager->id,
            'member_ids' => [$member->id, $otherMember->id],
        ])->assertOk()
            ->assertJsonPath('team.name', 'Platform')
            ->assertJsonCount(2, 'team.members');

        $this->assertDatabaseHas('team_user', [
            'team_id' => $team->id,
            'user_id' => $otherMember->id,
        ]);
    }

    public function test_member_can_belong_to_multiple_teams(): void
    {
        $manager = User::factory()->teamManager()->create();
        $member = User::factory()->member()->create();
        $firstTeam = Team::query()->create(['name' => 'Product', 'manager_id' => $manager->id]);
        $secondTeam = Team::query()->create(['name' => 'Support', 'manager_id' => $manager->id]);

        $firstTeam->members()->attach($member);
        $secondTeam->members()->attach($member);

        $this->assertCount(2, $member->fresh()->teams);
    }

    public function test_team_manager_can_view_only_managed_teams(): void
    {
        $manager = User::factory()->teamManager()->create();
        $otherManager = User::factory()->teamManager()->create();
        $member = User::factory()->member()->create(['name' => 'Visible Member']);
        $hiddenMember = User::factory()->member()->create(['name' => 'Hidden Member']);
        $team = Team::query()->create(['name' => 'Visible Team', 'manager_id' => $manager->id]);
        $hiddenTeam = Team::query()->create(['name' => 'Hidden Team', 'manager_id' => $otherManager->id]);
        $team->members()->attach($member);
        $hiddenTeam->members()->attach($hiddenMember);

        DailyTask::query()->create([
            'user_id' => $member->id,
            'work_date' => '2026-06-11',
            'title' => 'Visible task',
            'status' => 'planned',
        ]);
        DailyTask::query()->create([
            'user_id' => $hiddenMember->id,
            'work_date' => '2026-06-11',
            'title' => 'Hidden task',
            'status' => 'blocked',
        ]);

        $response = $this->actingAs($manager)->getJson('/manager/dashboard?date=2026-06-11');

        $response->assertOk()
            ->assertJsonCount(1, 'teams')
            ->assertJsonPath('teams.0.name', 'Visible Team')
            ->assertJsonPath('teams.0.members.0.name', 'Visible Member')
            ->assertJsonPath('teams.0.members.0.tasks.0.title', 'Visible task');

        $this->assertStringNotContainsString('Hidden Team', $response->getContent());
        $this->assertStringNotContainsString('Hidden task', $response->getContent());
    }

    public function test_team_manager_cannot_manage_member_tasks(): void
    {
        $manager = User::factory()->teamManager()->create();
        $member = User::factory()->member()->create();
        $task = DailyTask::query()->create([
            'user_id' => $member->id,
            'work_date' => today(config('app.timezone'))->toDateString(),
            'title' => 'Member task',
            'status' => 'planned',
        ]);

        $this->actingAs($manager)->postJson('/tasks', [
            'title' => 'Manager task',
            'status' => 'planned',
        ])->assertForbidden();

        $this->actingAs($manager)->putJson("/tasks/{$task->id}", [
            'title' => 'Changed',
            'status' => 'done',
        ])->assertForbidden();

        $this->actingAs($manager)->deleteJson("/tasks/{$task->id}")
            ->assertForbidden();
    }

    public function test_admin_dashboard_still_includes_members_without_teams(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->member()->create(['name' => 'Unassigned Member']);

        DailyTask::query()->create([
            'user_id' => $member->id,
            'work_date' => '2026-06-11',
            'title' => 'Standalone task',
            'status' => 'done',
        ]);

        $this->actingAs($admin)->getJson('/admin/dashboard?date=2026-06-11')
            ->assertOk()
            ->assertJsonPath('members.0.name', 'Unassigned Member')
            ->assertJsonPath('members.0.tasks.0.title', 'Standalone task');
    }

    public function test_manager_dashboard_respects_status_filter(): void
    {
        $manager = User::factory()->teamManager()->create();
        $member = User::factory()->member()->create();
        $team = Team::query()->create(['name' => 'Product', 'manager_id' => $manager->id]);
        $team->members()->attach($member);

        DailyTask::query()->create([
            'user_id' => $member->id,
            'work_date' => '2026-06-11',
            'title' => 'Blocked task',
            'status' => 'blocked',
        ]);
        DailyTask::query()->create([
            'user_id' => $member->id,
            'work_date' => '2026-06-11',
            'title' => 'Done task',
            'status' => 'done',
        ]);

        $response = $this->actingAs($manager)->getJson('/manager/dashboard?date=2026-06-11&status=blocked');

        $response->assertOk()
            ->assertJsonCount(1, 'teams.0.members.0.tasks')
            ->assertJsonPath('teams.0.members.0.tasks.0.title', 'Blocked task')
            ->assertJsonPath('teams.0.members.0.counts.blocked', 1)
            ->assertJsonPath('teams.0.members.0.counts.done', 0);
    }
}
