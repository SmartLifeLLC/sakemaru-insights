<?php

namespace App\Models\Sakemaru;

use Filament\Models\Contracts\FilamentUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable implements FilamentUser
{
    protected $connection = 'sakemaru';

    protected $table = 'users';

    protected $primaryKey = 'id';

    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'client_id',
        'name',
        'kana_name',
        'email',
        'code',
        'default_branch_id',
        'default_warehouse_id',
        'is_active',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_recovery_codes',
        'two_factor_secret',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    public function newQuery(): Builder
    {
        $query = parent::newQuery();

        return $query->where('users.is_active', true);
    }

    public function canAccessPanel(\Filament\Panel $panel): bool
    {
        return true;
    }
}
