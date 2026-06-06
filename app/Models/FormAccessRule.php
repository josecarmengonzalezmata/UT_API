<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class FormAccessRule extends Model
{
    protected $table = 'form_access_rules';
    public $timestamps = false;

    protected $fillable = [
        'form_id',
        'due_at',
        'updated_by',
    ];

    protected $casts = [
        'due_at' => 'datetime',
    ];

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class, 'form_id');
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'form_access_roles', 'form_access_rule_id', 'role_id');
    }
}
