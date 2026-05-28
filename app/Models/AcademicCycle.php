<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AcademicCycle extends Model
{
    protected $table = 'academic_cycles';

    protected $fillable = [
        'name',
        'year',
        'period_name',
        'start_date',
        'end_date',
        'status',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
    ];
}
