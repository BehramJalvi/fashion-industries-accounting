<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $attributes = ['is_active' => true];   // same default as the column

    protected $fillable = ['name', 'email', 'password', 'is_active'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'email_verified_at' => 'datetime',
        ];
    }

    public function roles(): BelongsToMany { return $this->belongsToMany(Role::class); }

    public function hasRole(string $name): bool
    {
        return $this->roles->contains('name', $name);
    }

    public function isAdmin(): bool
    {
        return $this->hasRole('admin');
    }

    /** Admin passes every check; others need the permission through one of their roles. */
    public function hasPermission(string $permission): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return $this->roles->loadMissing('permissions')
            ->flatMap->permissions->contains('name', $permission);
    }
}
