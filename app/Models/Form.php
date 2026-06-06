<?php

namespace App\Models;

use App\Models\FormAccessRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Form extends Model
{
    protected $table = 'forms';

    protected $fillable = [
        'form_code',
        'title',
        'section',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $appends = [
        'access_roles',
        'due_at',
    ];

    public function accessRule(): HasOne
    {
        return $this->hasOne(FormAccessRule::class, 'form_id');
    }

    public function getAccessRolesAttribute(): array
    {
        return $this->accessRule?->roles->pluck('code')->all() ?? [];
    }

    public function getDueAtAttribute(): ?string
    {
        return $this->accessRule?->due_at?->format('Y-m-d\TH:i');
    }
}
