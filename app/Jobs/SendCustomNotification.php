<?php


// app/Jobs/SendCustomNotification.php

namespace App\Jobs;

use App\Models\{User, SchoolSetting};
use App\Services\{WhatsAppService, WhatsAppMessageBuilder};
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class SendCustomNotification implements ShouldQueue
{
    use Queueable;

    public int $tries   = 3;
    public int $backoff = 60;

    public function __construct(
        public int    $schoolId,
        public int    $parentId,
        public string $message
    ) {}

    public function handle(WhatsAppService $whatsapp): void
    {
        $parent = User::where('id', $this->parentId)
            ->where('role', 'Parent')
            ->whereNotNull('whatsapp_number')
            ->first();

        if (!$parent) {
            Log::info("SendCustomNotification: parent {$this->parentId} not found or no WhatsApp number.");
            return;
        }

        $school = SchoolSetting::find($this->schoolId);

        if (!$school) {
            Log::warning("SendCustomNotification: school {$this->schoolId} not found.");
            return;
        }

        $builtMessage = WhatsAppMessageBuilder::custom(
            $this->schoolId,
            $parent->name,
            $this->message
        );

        $whatsapp->sendToParent(
            $this->schoolId,
            $parent->whatsapp_number,
            $builtMessage
        );
    }
}
