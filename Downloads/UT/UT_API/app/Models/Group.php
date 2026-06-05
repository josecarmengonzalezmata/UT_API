<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Group extends Model
{
    protected $table = 'groups';

    protected $fillable = [
        'career_id',
        'cycle_id',
        'cuatrimestre',
        'group_number',
        'group_code',
    ];
}
