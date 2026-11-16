<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class HotelPortalAccountController extends Controller
{
    public function show(int $id): JsonResponse
    {
        abort_unless(DB::table('customers')->where('id', $id)->exists(), 404);

        return $this->response(User::where('customer_id', $id)->first());
    }

    public function save(Request $request, int $id): JsonResponse
    {
        $input = $request->validate([
            'username' => ['required', 'string', 'min:3', 'max:64'],
            'password' => ['nullable', 'string', 'min:12', 'max:72'],
        ]);
        $username = Str::lower(trim($input['username']));
        if (! preg_match('/\A[a-z0-9][a-z0-9._-]{2,63}\z/', $username) && ! filter_var($username, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['username' => 'Enter an email address or a username with letters, numbers, dots, underscores, or hyphens.']);
        }
        $password = $input['password'] ?? null;
        if ($password !== null && (strlen($password) > 72 || str_contains($password, "\0"))) {
            throw ValidationException::withMessages(['password' => 'Use a password of 12 or more characters and at most 72 UTF-8 bytes.']);
        }

        try {
            $account = DB::transaction(function () use ($id, $username, $password) {
                abort_unless(DB::table('customers')->where('id', $id)->lockForUpdate()->first(), 404);
                $current = User::where('customer_id', $id)->first();
                if (! $current && $password === null) {
                    throw ValidationException::withMessages(['password' => 'Set a password of at least 12 characters for a new hotel account.']);
                }

                $account = $current ?? new User;
                $account->customer_id = $id;
                $account->username = $username;
                if ($password !== null) {
                    $account->password = $password;
                }
                $changed = $account->isDirty(['username', 'password']);
                $account->save();
                if ($current && $changed && config('session.driver') === 'database') {
                    DB::connection(config('session.connection'))
                        ->table(config('session.table'))
                        ->where('user_id', $account->id)->delete();
                }

                return $account;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['username' => 'That username is already in use.']);
        }

        return $this->response($account);
    }

    private function response(?User $account): JsonResponse
    {
        return response()->json(['account' => $account ? ['username' => $account->username] : null]);
    }
}
