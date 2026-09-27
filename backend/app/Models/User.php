<?php

namespace App\Models;

use App\Enums\Locale;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

    protected $fillable = [
        'name',
        'phone',
        'email',
        'password',
        'gender',
        'locale',
        'is_active',
        'phone_verified_at',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'locale' => Locale::class,
            'phone_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public function teacher(): HasOne
    {
        return $this->hasOne(Teacher::class);
    }

    /** Student profile when this user is a student login. */
    public function student(): HasOne
    {
        return $this->hasOne(Student::class, 'user_id');
    }

    /** Children when this user is a guardian login. */
    public function children(): HasMany
    {
        return $this->hasMany(Student::class, 'guardian_user_id');
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class, 'teacher_id');
    }
}
