<?php

// app/Services/WhatsAppMessageBuilder.php

namespace App\Services;

use App\Models\{User, Average, SchoolSetting};

class WhatsAppMessageBuilder
{
    public static function result(User $student, Average $average, User $parent): string
    {
        $school = SchoolSetting::find($student->school_id);

        return "📊 *{$school->school_name}*\n\n"
            . "Dear {$parent->name},\n\n"
            . "The result for *{$student->name}* — "
            . "*{$average->term} {$average->session}* is ready.\n\n"
            . "🏆 Position: {$average->position} of {$average->class_size}\n"
            . "📈 Average: {$average->total_average}%\n"
            . "📝 Remark: {$average->general_remark}\n"
            . "👨‍🏫 Class Teacher: {$average->class_teacher}\n\n"
            . "✅ Days Present: {$average->no_present} "
            . "| ❌ Absent: {$average->no_absent}\n\n"
            . "📄 Full result sheet is attached.\n\n"
            . "_{$school->school_name}_";
    }

    public static function feeReminder(User $student, $invoice, User $parent): string
    {
        $school = SchoolSetting::find($student->school_id);

        return "💳 *{$school->school_name} — Fee Reminder*\n\n"
            . "Dear {$parent->name},\n\n"
            . "This is a reminder for an outstanding fee on *{$student->name}*'s account:\n\n"
            . "📌 {$invoice->title}\n"
            . "💰 Amount Due: ₦" . number_format($invoice->balance) . "\n"
            . "📅 Due Date: {$invoice->due_date->format('d M Y')}\n\n"
            . "Please make payment before the due date.\n"
            . "_{$school->school_name}_";
    }

    public static function custom(int $schoolId, string $parentName, string $body): string
    {
        $school = SchoolSetting::find($schoolId);

        return "*{$school->school_name}*\n\n"
            . "Dear {$parentName},\n\n"
            . "{$body}\n\n"
            . "_{$school->school_name}_";
    }
}