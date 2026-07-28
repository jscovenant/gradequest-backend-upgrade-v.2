<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WhatsAppBroadcastDelivery extends Model
{
    use BelongsToSchool;
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public static function periodKey(?string $term, ?string $session, ?int $classId = null, ?int $studentId = null): string
    {
        return collect([
            'term:' . strtolower(trim((string) $term)),
            'session:' . strtolower(trim((string) $session)),
            $classId ? 'class:' . $classId : null,
            $studentId ? 'student:' . $studentId : null,
        ])->filter()->implode('|');
    }

    public static function hasSuccessfulSend(
        int $schoolId,
        int $parentId,
        string $broadcastType,
        string $periodKey
    ): bool {
        return static::where('school_id', $schoolId)
            ->where('parent_id', $parentId)
            ->where('broadcast_type', $broadcastType)
            ->where('period_key', $periodKey)
            ->where('status', 'sent')
            ->exists();
    }

    public static function hasActiveSend(
        int $schoolId,
        int $parentId,
        string $broadcastType,
        string $periodKey
    ): bool {
        return static::where('school_id', $schoolId)
            ->where('parent_id', $parentId)
            ->where('broadcast_type', $broadcastType)
            ->where('period_key', $periodKey)
            ->whereIn('status', ['pending', 'sending', 'sent'])
            ->exists();
    }
}
