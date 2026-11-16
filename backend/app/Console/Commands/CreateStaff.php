<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

class CreateStaff extends Command
{
    protected $signature = 'staff:create {username : A unique login name}';

    protected $description = 'Create a named OrderDesk staff account';

    public function handle(): int
    {
        $username = Str::lower(trim((string) $this->argument('username')));
        if (! preg_match('/\A[a-z0-9][a-z0-9._-]{2,63}\z/', $username)) {
            $this->error('Use 3–64 characters: letters, numbers, dots, underscores, or hyphens. Start with a letter or number.');

            return self::FAILURE;
        }

        if (User::where('username', $username)->exists()) {
            $this->error('That staff username already exists.');

            return self::FAILURE;
        }

        $password = $this->secret('Password (12 characters minimum)', false);
        if (! is_string($password) || ! mb_check_encoding($password, 'UTF-8') || mb_strlen($password) < 12 || strlen($password) > 72 || str_contains($password, "\0")) {
            $this->error('Use a password of at least 12 characters and no more than 72 UTF-8 bytes.');

            return self::FAILURE;
        }

        $confirmation = $this->secret('Confirm password', false);
        if (! is_string($confirmation) || ! hash_equals($password, $confirmation)) {
            $this->error('Passwords did not match.');

            return self::FAILURE;
        }

        try {
            User::create(['username' => $username, 'password' => $password]);
        } catch (UniqueConstraintViolationException) {
            $this->error('That staff username already exists.');

            return self::FAILURE;
        }

        $this->info("Staff account created: {$username}");

        return self::SUCCESS;
    }
}
