<?php

namespace App\Services;

use App\Models\Average;
use App\Models\SchoolSetting;
use App\Models\StudentFee;
use App\Models\User;
use Illuminate\Support\Collection;

class WhatsAppMessageBuilder
{
    public static function result(User $student, ?Average $average, User $parent, ?string $pin = null, ?string $resultLink = null): string
    {
        $school = SchoolSetting::find($student->school_id);
        $schoolName = $school?->school_name ?? 'School';
        $studentName = self::name($student);
        $parentName = self::name($parent);

        $lines = [
            "*{$schoolName}*",
            "",
            "Dear {$parentName},",
            "",
            "This is to notify you that the result for *{$studentName}* has been released.",
        ];

        if ($average) {
            $lines[] = "";
            $lines[] = "Term/Session: {$average->term} {$average->session}";
            $lines[] = "Average: {$average->total_average}%";

            if (! empty($average->position)) {
                $lines[] = "Position: {$average->position}" . (! empty($average->class_size) ? " of {$average->class_size}" : '');
            }

            if (! empty($average->general_remark)) {
                $lines[] = "Remark: {$average->general_remark}";
            }
        }

        $lines[] = "";
        if ($pin && $resultLink) {
            $lines[] = "Result PIN: *{$pin}*";
            $lines[] = "PIN validity: 30 days";
            $lines[] = "Maximum checks: 5 times";
            $lines[] = "";
            $lines[] = "Tap this link to check the result:";
            $lines[] = $resultLink;
        } else {
            $lines[] = "Please login to GradeQuest or contact the school for more details.";
        }
        $lines[] = "";
        $lines[] = "_{$schoolName}_";

        return implode("\n", $lines);
    }

    public static function feeReminder(Collection $fees, User $parent, array $paymentLinks = [], ?Collection $bankAccounts = null): string
    {
        /** @var StudentFee|null $firstFee */
        $firstFee = $fees->first();

        if (! $firstFee) {
            return '';
        }

        $school = SchoolSetting::find($firstFee->school_id);
        $schoolName = $school?->school_name ?? 'School';
        $parentName = self::name($parent);
        $totalBalance = (float) $fees->sum('balance');

        $lines = [
            "*{$schoolName} - Fee Reminder*",
            "",
            "Dear {$parentName},",
            "",
            "This is to remind you that there is still an outstanding school fee balance for your child/children.",
            "Kindly review the fee breakdown below and make payment as soon as possible.",
            "",
            "*Outstanding Fee Breakdown*",
        ];

        foreach ($fees->groupBy('student_id')->values() as $studentIndex => $studentFees) {
            $student = $studentFees->first()?->student;
            $studentName = self::name($student);
            $studentRegNo = $student?->reg_no ? " ({$student->reg_no})" : '';
            $studentTotal = (float) $studentFees->sum('balance');

            $lines[] = "";
            $lines[] = ($studentIndex + 1) . ". *{$studentName}{$studentRegNo}*";

            foreach ($studentFees->values() as $feeIndex => $fee) {
                $feeName = $fee->feeType?->name ?? 'School fee';
                $term = $fee->term?->name;
                $session = $fee->session?->name;
                $period = trim(implode(' - ', array_filter([$term, $session])));
                $balance = number_format((float) $fee->balance, 2);

                $lines[] = "   " . ($feeIndex + 1) . ") {$feeName}" . ($period ? " - {$period}" : "") . ": NGN {$balance}";
            }

            $lines[] = "   *Child Total: NGN " . number_format($studentTotal, 2) . "*";

            if ($student && isset($paymentLinks[(int) $student->id])) {
                $lines[] = "   Tap this link to pay online:";
                $lines[] = $paymentLinks[(int) $student->id];
            }
        }

        $lines[] = "";
        $lines[] = "*Grand Total Due: NGN " . number_format($totalBalance, 2) . "*";
        $lines[] = "";

        if (! empty($paymentLinks)) {
            $lines[] = "You may pay securely online using the payment link shown under each child.";
        }

        if ($bankAccounts && $bankAccounts->isNotEmpty()) {
            $lines[] = "";
            $lines[] = "*Bank Transfer Option*";
            $lines[] = "If you prefer bank transfer, please use any of the account details below:";

            foreach ($bankAccounts->take(3) as $account) {
                $lines[] = trim(implode(' | ', array_filter([
                    $account->bank_name ?? null,
                    $account->account_number ?? null,
                    $account->account_name ?? null,
                ])));
            }

            $lines[] = "After making a transfer, please send your payment receipt to the school bursary/admin office for verification.";
        } elseif (empty($paymentLinks)) {
            $lines[] = "Please contact the school bursary/admin office for payment details and receipt verification.";
        }

        $lines[] = "";
        $lines[] = "If payment has already been made, kindly disregard this reminder after confirmation by the school.";
        $lines[] = "";
        $lines[] = "_{$schoolName}_";

        return implode("\n", $lines);
    }

    public static function custom(int $schoolId, string $parentName, string $body): string
    {
        $school = SchoolSetting::find($schoolId);
        $schoolName = $school?->school_name ?? 'School';

        return "*{$schoolName}*\n\n"
            . "Dear {$parentName},\n\n"
            . trim($body) . "\n\n"
            . "_{$schoolName}_";
    }

    private static function name(?User $user): string
    {
        if (! $user) {
            return 'Parent';
        }

        return trim(implode(' ', array_filter([
            $user->firstname ?? null,
            $user->surname ?? null,
        ]))) ?: ($user->name ?? $user->email ?? 'Parent');
    }
}
