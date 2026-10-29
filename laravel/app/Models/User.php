<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use RuntimeException;

#[Fillable(['name', 'username', 'email', 'phone', 'address', 'role', 'status', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $attributes = [
        'role' => 'customer',
    ];

    protected static function booted(): void
    {
        static::saving(function (User $user): void {
            $roleCode = $user->getAttribute('role');
            $role = $roleCode ? Role::query()->where('code', $roleCode)->first() : null;
            if (! $role) {
                throw new RuntimeException('Tài khoản phải được gán một vai trò đã khai báo.');
            }

            $user->setAttribute('role', $role->code);
            $user->setAttribute('role_id', $role->id);
            $user->unsetRelation('roleDefinition');
        });
    }

    public function roleDefinition(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    public function hasPermission(string $code): bool
    {
        if (! $this->exists || ! $this->getAttribute('role_id')) {
            return false;
        }

        $role = $this->loadMissing('roleDefinition.permissions')->roleDefinition;
        if (! $role) {
            return false;
        }

        return $role->permissions->contains('code', $code);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'locked_until' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
