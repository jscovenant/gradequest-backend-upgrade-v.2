<?php


// app/Jobs/SendResultNotification.php

namespace App\Jobs;

use App\Models\{User, Average, ParentStudent};
use App\Services\{WhatsAppService, ResultPdfService, WhatsAppMessageBuilder};
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class SendResultNotification implements ShouldQueue
{
    use Queueable;

    public int $tries   = 3;
    public int $backoff = 60;

    public function __construct(
        public int    $studentId,
        public int    $classId,
        public string $term,
        public string $session
    ) {}

    public function handle(WhatsAppService $whatsapp, ResultPdfService $pdfService): void
    {
        $student = User::with('section')->find($this->studentId);
        if (!$student) return;

        // ✅ Get ALL linked parents for this student
        $parentLinks = ParentStudent::where('student_id', $student->id)
            ->where('school_id', $student->school_id)
            ->get();

        if ($parentLinks->isEmpty()) {
            Log::info("No parents linked for student {$this->studentId}");
            return;
        }

        // ✅ Generate PDF once — reused for all parents
        $pdfPath = $pdfService->generate(
            $this->studentId,
            $this->classId,
            $this->term,
            $this->session
        );

        if (!$pdfPath) {
            Log::warning("PDF generation failed for student {$this->studentId}");
            return;
        }

        // ✅ Load average once — reused for all parents
        $average = Average::where('user_id', $this->studentId)
            ->where('class_id', $this->classId)
            ->where('term', $this->term)
            ->where('session', $this->session)
            ->first();

        // ✅ Send to each linked parent
        foreach ($parentLinks as $link) {
            $parent = User::where('id', $link->parent_id)
                ->where('role', 'Parent')
                ->whereNotNull('whatsapp_number')
                ->first();

            if (!$parent) continue;

            $message = WhatsAppMessageBuilder::result($student, $average, $parent);

            $whatsapp->sendToParent(
                $student->school_id,
                $parent->whatsapp_number,
                $message,
                $pdfPath
            );

            sleep(1); 
        }

        // ✅ Cleanup after all parents have been sent to
        $this->cleanup($pdfPath);
    }

    private function cleanup(string $pdfPath): void
    {
        if (file_exists($pdfPath)) unlink($pdfPath);

        $publicTemp = storage_path('app/public/whatsapp_temp/' . basename($pdfPath));
        if (file_exists($publicTemp)) unlink($publicTemp);
    }
}