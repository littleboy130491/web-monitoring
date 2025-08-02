<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Website extends Model
{
    protected $fillable = [
        'url',
        'description',
        'is_active',
        'check_interval',
        'headers',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'headers' => 'array',
        'check_interval' => 'integer',
    ];

    public function monitoringResults(): HasMany
    {
        return $this->hasMany(MonitoringResult::class);
    }

    public function latestResult()
    {
        return $this->hasOne(MonitoringResult::class)->latestOfMany();
    }
}
