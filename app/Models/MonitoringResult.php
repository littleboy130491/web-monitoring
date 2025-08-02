<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonitoringResult extends Model
{
    protected $fillable = [
        'website_id',
        'status_code',
        'response_time',
        'status',
        'error_message',
        'content_hash',
        'content_changed',
        'screenshot_path',
        'headers',
        'ssl_info',
        'checked_at',
    ];

    protected $casts = [
        'content_changed' => 'boolean',
        'ssl_info' => 'array',
        'checked_at' => 'datetime',
        'status_code' => 'integer',
        'response_time' => 'integer',
    ];

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function isUp(): bool
    {
        return $this->status === 'up';
    }

    public function isDown(): bool
    {
        return $this->status === 'down';
    }
}
