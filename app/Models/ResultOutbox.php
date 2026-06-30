<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ResultOutbox extends Model
{
  protected $table = 'result_outbox';

    protected $fillable = [
        'school_id', 'result_id', 'status', 'attempts', 'max_attempts',
        'next_attempt_at', 'delivered_at', 'last_http_status',
        'last_error', 'idempotency_key',
    ];

    protected $casts = [
        'next_attempt_at' => 'datetime',
        'delivered_at'    => 'datetime',
    ];

    // ─── Relationships ────────────────────────────────────────────────────

    public function school()
    {
        return $this->belongsTo(SchoolSetting::class);
    }

    public function result()
    {
        return $this->belongsTo(StudentResultV2::class, 'result_id');
    }

    // ─── Scopes ───────────────────────────────────────────────────────────

    public function scopePending($query)
    {
        return $query->where('status', 'pending')
                     ->where(fn($q) => $q->whereNull('next_attempt_at')
                                         ->orWhere('next_attempt_at', '<=', now()));
    }

    // ─── Factory ──────────────────────────────────────────────────────────

    /**
     * Create an outbox entry for a result that needs to be pushed to SMS.
     * Called immediately after result computation.
     */
    public static function createForResult(StudentResultV2 $result): self
    {
        return self::firstOrCreate(
            ['result_id' => $result->id],
            [
                'school_id'       => $result->exam->school_id,
                'status'          => 'pending',
                'max_attempts'    => 5,
                'idempotency_key' => Str::uuid()->toString(),
                'next_attempt_at' => now(),
            ]
        );
    }

    // ─── State transitions ────────────────────────────────────────────────

    public function markDelivered(int $httpStatus): void
    {
        $this->update([
            'status'           => 'delivered',
            'delivered_at'     => now(),
            'last_http_status' => $httpStatus,
            'last_error'       => null,
        ]);
    }

    public function scheduleRetry(string $error, int $httpStatus = 0): void
    {
        $backoffMinutes = [2, 10, 30, 120, 360][$this->attempts - 1] ?? 360;

        if ($this->attempts >= $this->max_attempts) {
            $this->update(['status' => 'abandoned', 'last_error' => $error]);
            return;
        }

        $this->update([
            'status'           => 'pending',
            'last_error'       => $error,
            'last_http_status' => $httpStatus,
            'next_attempt_at'  => now()->addMinutes($backoffMinutes),
        ]);
    }
}
