<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MonitoringReport extends Model
{
    protected $fillable = [
        'recipient',
        'subject',
        'body_html',
        'summary',
        'status',
        'error_message',
        'sent_at',
        'triggered_by',
    ];

    protected $casts = [
        'summary'  => 'array',
        'sent_at'  => 'datetime',
    ];

    public function isSent(): bool
    {
        return $this->status === 'sent';
    }

    public function hasFailed(): bool
    {
        return $this->status === 'failed';
    }

    /** Total flagged website count across all issue types. */
    public function flaggedCount(): int
    {
        $s = $this->summary ?? [];
        return count($s['down'] ?? [])
            + count($s['expiring'] ?? [])
            + count($s['content_changed'] ?? [])
            + count($s['broken_assets'] ?? []);
    }
}
