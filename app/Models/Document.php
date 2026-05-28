<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    protected $table = 'documents';

    protected $fillable = [
        'form_id',
        'cycle_id',
        'uploaded_by',
        'assigned_reviewer_id',
        'title',
        'apartado_label',
        'plan',
        'carrera_label',
        'materia',
        'parcial',
        'group_id',
        'file_path',
        'mime_type',
        'file_size_bytes',
        'status',
        'submitted_at',
        'reviewed_at',
        'returned_at',
        'resubmitted_at',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'returned_at' => 'datetime',
        'resubmitted_at' => 'datetime',
    ];
}
