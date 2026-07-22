<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailSuppression extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'email',
        'reason',
        'provider',
        'suppressed_at',
        'metadata',
    ];

    protected $casts = [
        'suppressed_at' => 'datetime',
        'metadata' => 'array',
    ];
}
