<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class OwnerSeeder extends Seeder
{
    public const EMAIL = 'admin@admin.com';

    public function run(): void
    {
        $password = config('initial_admin.password');

        if (! is_string($password) || trim($password) === '' || ! mb_check_encoding($password, 'UTF-8') || mb_strlen($password) < 12 || strlen($password) > 72 || str_contains($password, "\0")) {
            throw new RuntimeException('Set ORDERDESK_ADMIN_PASSWORD in backend/.env (12+ characters, up to 72 UTF-8 bytes), then retry the command.');
        }

        if (User::where('username', self::EMAIL)->exists()) {
            return;
        }

        User::firstOrCreate(
            ['username' => self::EMAIL],
            ['email' => self::EMAIL, 'password' => Hash::make($password)],
        );
    }
}
