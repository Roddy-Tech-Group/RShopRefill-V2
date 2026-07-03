<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NotificationTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'channels',
        'title_template',
        'body_template',
        'action_url',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'channels' => 'array',
            'metadata' => 'array',
        ];
    }
}
