<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AttendanceMarked
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $schoolId,
        public readonly int $classId,
        public readonly string $date,
        public readonly array $studentStatuses,
    ) {
    }
}
