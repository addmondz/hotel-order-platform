<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    protected $fillable = ['username', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    public function isHotel(): bool
    {
        return $this->customer_id !== null;
    }

    protected function casts(): array
    {
        return ['password' => 'hashed', 'customer_id' => 'integer'];
    }
}
