<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ResultPublished
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $batchId,
        public readonly int $schoolId,
    ) {
    }
}
