<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FeatureFlag extends Model
{
    protected $fillable = ['key', 'status', 'percentage'];

    public const STATUS_ENABLED = 'enabled';
    public const STATUS_DISABLED = 'disabled';
    public const STATUS_MAINTENANCE = 'maintenance';
    public const STATUS_STAGED = 'staged';
}
