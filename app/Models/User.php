<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;
    use Notifiable;

    protected $table = 'users';

    protected $fillable = [
        'full_name',
        'email',
        'password_hash',
        'phone',
        'area',
        'avatar_url',
        'is_active',
    ];

    protected $hidden = [
        'password_hash',
        'remember_token',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
protected $appends = ['avatar'];

public function getAvatarAttribute(): string
{
    if ($this->avatar_url) {
        if (str_starts_with($this->avatar_url, 'http') || 
            str_starts_with($this->avatar_url, '/api/users/') ||
            str_starts_with($this->avatar_url, '/storage/')) {
            return $this->avatar_url;
        }
        return '/storage/' . ltrim($this->avatar_url, '/');
    }
    
    // Iniciales
    $parts = preg_split('/\s+/', trim($this->full_name)) ?: [];
    if (empty($parts)) return 'U';
    
    $firstInitial = mb_strtoupper(mb_substr($parts[0], 0, 1));
    if (count($parts) > 1) {
        $lastInitial = mb_strtoupper(mb_substr(end($parts), 0, 1));
        return $firstInitial . $lastInitial;
    }
    
    return $firstInitial;
}
    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'user_roles', 'user_id', 'role_id')->withPivot('assigned_at');
    }
}
