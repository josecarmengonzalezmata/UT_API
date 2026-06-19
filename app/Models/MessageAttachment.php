<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MessageAttachment extends Model
{
    protected $table = 'message_attachments';

    protected $fillable = [
        'message_id',
        'file_name',
        'file_path',
        'file_size_bytes',
        'file_type_label',
    ];

    public function message()
    {
        return $this->belongsTo(Message::class, 'message_id');
    }
}
