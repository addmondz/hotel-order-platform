<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\OwnerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class OwnerSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_seeder_creates_a_sign_in_ready_owner_and_never_resets_it(): void
    {
        config(['initial_admin.password' => 'local-seeder-test-fixture-1']);
        $this->seed();

        $owner = User::where('email', OwnerSeeder::EMAIL)->sole();
        $this->assertSame(OwnerSeeder::EMAIL, $owner->username);
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('orders', 0);
        $hash = $owner->password;
        $this->assertTrue(Hash::check('local-seeder-test-fixture-1', $hash));

        config(['initial_admin.password' => 'local-seeder-test-fixture-2']);
        $this->seed();
        $this->assertDatabaseCount('users', 1);
        $this->assertSame($hash, $owner->fresh()->password);
        $this->postJson('/api/login', ['username' => OwnerSeeder::EMAIL, 'password' => 'local-seeder-test-fixture-1'])
            ->assertOk()->assertJsonPath('username', OwnerSeeder::EMAIL);
    }

    public function test_blank_password_is_rejected_even_when_an_owner_exists(): void
    {
        config(['initial_admin.password' => 'local-seeder-test-fixture-1']);
        $this->seed();

        config(['initial_admin.password' => '               ']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Set ADMIN_PASSWORD');
        $this->seed();
    }

    public function test_blank_password_does_not_create_a_default_owner(): void
    {
        config(['initial_admin.password' => null]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Set ADMIN_PASSWORD');
        $this->seed();
    }
}
