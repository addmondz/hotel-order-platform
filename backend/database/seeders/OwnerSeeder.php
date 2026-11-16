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
        if (User::where('username', self::EMAIL)->exists()) {
            return;
        }

        $password = config('initial_admin.password');

        if (! is_string($password) || ! mb_check_encoding($password, 'UTF-8') || mb_strlen($password) < 12 || strlen($password) > 72 || str_contains($password, "\0")) {
            throw new RuntimeException('Set ORDERDESK_ADMIN_PASSWORD in backend/.env to a password of at least 12 characters and no more than 72 UTF-8 bytes, then run php artisan db:seed.');
        }

        User::firstOrCreate(
            ['username' => self::EMAIL],
            ['email' => self::EMAIL, 'password' => Hash::make($password)],
        );
    }
}
