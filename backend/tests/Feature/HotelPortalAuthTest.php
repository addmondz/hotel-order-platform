<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class HotelPortalAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_portal_and_admin_have_separate_login_endpoints_and_roles(): void
    {
        $staff = User::create(['username' => 'test.staff', 'password' => 'staff-test-password']);
        $hotel = $this->hotel();
        $credentials = ['username' => 'manager@example.test', 'password' => 'hotel-test-password'];
        $owner = new User($credentials);
        $owner->customer_id = $hotel;
        $owner->save();

        $this->postJson('/api/login', $credentials)->assertUnauthorized();
        $this->assertGuest();
        $this->postJson('/api/portal/login', ['username' => $staff->username, 'password' => 'staff-test-password'])
            ->assertUnauthorized();
        $this->assertGuest();

        $summary = ['id' => $owner->id, 'username' => $owner->username, 'role' => 'hotel', 'customerId' => $hotel];
        $this->postJson('/api/portal/login', $credentials)->assertExactJson($summary);
        $this->getJson('/api/me')->assertExactJson($summary);
        $this->postJson('/api/login', $credentials)->assertConflict();

        foreach (['customers', 'orders', 'products', "prices?customerId={$hotel}", "customers/{$hotel}/portal-account", "customers/{$hotel}/transactions", 'orders/1/payments', 'orders/1/payments/1/receipt'] as $path) {
            $this->getJson('/api/'.$path)->assertForbidden();
        }
        $this->postJson('/api/orders')->assertForbidden();
        $this->putJson("/api/customers/{$hotel}/portal-account", $credentials)->assertForbidden();
        $this->postJson('/api/logout')->assertOk();

        $this->actingAs($staff)->getJson('/api/portal/catalog')->assertForbidden();
        $this->actingAs($staff)->postJson('/api/portal/orders')->assertForbidden();
        $this->actingAs($staff)->getJson('/api/portal/transactions')->assertForbidden();
    }

    public function test_staff_can_manage_only_one_portal_account_per_hotel(): void
    {
        $staff = User::create(['username' => 'test.staff', 'password' => 'staff-test-password']);
        $hotel = $this->hotel();
        $other = $this->hotel();
        $endpoint = "/api/customers/{$hotel}/portal-account";
        $this->actingAs($staff)->getJson($endpoint)->assertExactJson(['account' => null]);
        $this->putJson($endpoint, ['username' => 'Manager@example.test'])->assertUnprocessable();
        $this->putJson($endpoint, ['username' => 'test.staff', 'password' => 'hotel-test-password'])
            ->assertUnprocessable();
        $this->putJson($endpoint, ['username' => 'Manager@example.test', 'password' => 'hotel-test-password'])
            ->assertExactJson(['account' => ['username' => 'manager@example.test']]);
        $account = User::where('customer_id', $hotel)->firstOrFail();
        $this->assertTrue(Hash::check('hotel-test-password', $account->password));
        $this->putJson("/api/customers/{$other}/portal-account", ['username' => 'manager@example.test', 'password' => 'hotel-test-password'])
            ->assertUnprocessable();
        $this->putJson($endpoint, ['username' => 'updated@example.test'])
            ->assertExactJson(['account' => ['username' => 'updated@example.test']]);
        $this->assertTrue(Hash::check('hotel-test-password', $account->refresh()->password));
        $this->assertSame(1, User::where('customer_id', $hotel)->count());
    }

    public function test_portal_account_updates_revoke_only_the_hotel_database_sessions(): void
    {
        config(['session.driver' => 'database']);
        $staff = User::create(['username' => 'test.staff', 'password' => 'staff-test-password']);
        $hotel = $this->hotel();
        $endpoint = "/api/customers/{$hotel}/portal-account";
        $this->actingAs($staff)->putJson($endpoint, ['username' => 'manager', 'password' => 'hotel-test-password'])->assertOk();
        $account = User::where('customer_id', $hotel)->firstOrFail();
        DB::table('sessions')->insert([
            ['id' => 'fixture-hotel-session', 'user_id' => $account->id, 'payload' => '', 'last_activity' => time()],
            ['id' => 'fixture-staff-session', 'user_id' => $staff->id, 'payload' => '', 'last_activity' => time()],
        ]);

        $this->putJson($endpoint, ['username' => 'manager', 'password' => 'updated-test-password'])->assertOk();
        $this->assertDatabaseMissing('sessions', ['id' => 'fixture-hotel-session']);
        $this->assertDatabaseHas('sessions', ['id' => 'fixture-staff-session']);
    }

    public function test_spa_entry_points_serve_the_build_and_keep_unknown_paths_as_404(): void
    {
        $dir = sys_get_temp_dir().'/orderdesk-spa-test-'.bin2hex(random_bytes(8));
        File::makeDirectory($dir.'/dist', 0700, true);
        File::put($dir.'/dist/index.html', '<!doctype html><title>OrderDesk test build</title>');
        $this->app->usePublicPath($dir);

        try {
            foreach (['/', '/customer', '/customer/orders', '/customer/catalog', '/customer/transactions', '/admin', '/admin/orders', '/admin/hotels', '/admin/products', '/admin/prices'] as $url) {
                $this->get($url)->assertOk()->assertHeader('Content-Type', 'text/html; charset=utf-8');
            }
            $this->get('/customer/unknown')->assertNotFound();
            $this->getJson('/api/unknown')->assertNotFound();
        } finally {
            File::deleteDirectory($dir);
        }
    }

    private function hotel(): int
    {
        return DB::table('customers')->insertGetId(['name' => 'Test hotel', 'phone' => '', 'notes' => '']);
    }
}
