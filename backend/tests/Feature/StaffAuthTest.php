<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StaffAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_authenticate_and_logout(): void
    {
        $this->getJson('/api/me')->assertUnauthorized();
        $this->getJson('/api/orders')->assertUnauthorized();
        $this->getJson('/api/csrf')->assertOk()->assertJsonStructure(['token']);

        $user = User::create(['username' => 'staff.member', 'password' => 'local-test-case-pass']);
        $this->postJson('/api/login', ['username' => 'Staff.Member', 'password' => 'local-test-case-pass'])
            ->assertOk()
            ->assertExactJson(['id' => $user->id, 'username' => 'staff.member']);
        $this->assertAuthenticatedAs($user);
        $this->getJson('/api/me')->assertExactJson(['id' => $user->id, 'username' => 'staff.member']);
        $this->postJson('/api/logout')->assertOk()->assertExactJson(['ok' => true]);
        $this->assertGuest();
        $this->getJson('/api/me')->assertUnauthorized();
    }

    public function test_repeated_invalid_credentials_are_throttled(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', ['username' => 'does-not-exist', 'password' => 'local-test-case-pass'])
                ->assertUnauthorized();
        }

        $this->postJson('/api/login', ['username' => 'does-not-exist', 'password' => 'local-test-case-pass'])
            ->assertTooManyRequests()
            ->assertHeader('Retry-After');
        $this->assertGuest();
    }

    public function test_staff_can_be_created_using_artisan(): void
    {
        $this->artisan('staff:create', ['username' => 'Staff.New'])
            ->expectsQuestion('Password (12 characters minimum)', 'another-local-test-case-pass')
            ->expectsQuestion('Confirm password', 'another-local-test-case-pass')
            ->assertSuccessful();

        $user = User::where('username', 'staff.new')->firstOrFail();
        $this->assertTrue(Hash::check('another-local-test-case-pass', $user->password));

        $this->artisan('staff:create', ['username' => 'staff.new'])->assertFailed();
    }

    public function test_staff_creation_rejects_weak_passwords(): void
    {
        $this->artisan('staff:create', ['username' => 'staff.weak'])
            ->expectsQuestion('Password (12 characters minimum)', 'too-short')
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['username' => 'staff.weak']);
    }
}
