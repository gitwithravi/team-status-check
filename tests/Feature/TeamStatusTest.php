<?php

namespace Tests\Feature;

use App\Models\DailyTask;
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
}
