<?php

namespace App\Services;

use App\Models\User;
use App\Models\Average;
use App\Models\Subject;
use App\Models\StudentClass;
use App\Models\SchoolSetting;
use App\Models\FirstTermResult;
use App\Models\SecondTermResult;
use App\Models\ThirdTermResult;
use App\Models\GradingForJunior;
use App\Models\GradingForSenior;
use Illuminate\Support\Facades\DB;

class ResultService
{
    public function build(User $user, int $classId, string $term, string $session): array
    {
        $schoolId = $user->school_id;

        // -----------------------------
        // 1️⃣ FETCH AVERAGE RECORD STRICTLY FOR CURRENT SESSION & TERM
        // -----------------------------
        $average = Average::where([
            'user_id'  => $user->id,
            'class_id' => $classId,
            'term'     => $term,
            'session'  => $session,
        ])->with('class')->firstOrFail();

        // -----------------------------
        // 2️⃣ FETCH CLASS & SUBJECTS
        // -----------------------------
        $class = StudentClass::findOrFail($classId);

        $subjects = Subject::where('school_id', $schoolId)
            ->where('class_id', $classId)
            ->get();

        // -----------------------------
        // 3️⃣ FETCH TERM RESULT MODEL
        // -----------------------------
        $termModels = [
            'First Term'  => FirstTermResult::class,
            'Second Term' => SecondTermResult::class,
            'Third Term'  => ThirdTermResult::class,
        ];

        if (!isset($termModels[$term])) {
            throw new \Exception('Unsupported term');
        }

        // 🔑 Fetch term results strictly for this student/class and session/term via Average
        $termResult = $termModels[$term]::where('user_id', $user->id)
            ->where('class_id', $classId)
            ->where('average_id', $average->id)
            ->with('subject')
            ->get();

        // -----------------------------
        // 4️⃣ SCHOOL INFO & ASSETS
        // -----------------------------
        $school = SchoolSetting::findOrFail($schoolId);

        $logoBase64 = $school->logo && file_exists(public_path($school->logo))
            ? 'data:' . mime_content_type(public_path($school->logo)) . ';base64,' . base64_encode(file_get_contents(public_path($school->logo)))
            : null;

        $signatureBase64 = $school->principal_signature && file_exists(public_path($school->principal_signature))
            ? 'data:' . mime_content_type(public_path($school->principal_signature)) . ';base64,' . base64_encode(file_get_contents(public_path($school->principal_signature)))
            : null;

        $photoPath = $user->photo ? public_path('uploads/users/' . $user->photo) : null;
        $photoBase64 = ($photoPath && file_exists($photoPath))
            ? 'data:' . mime_content_type($photoPath) . ';base64,' . base64_encode(file_get_contents($photoPath))
            : null;

        // -----------------------------
        // 5️⃣ AFFECTIVE & PSYCHOMOTOR DOMAINS
        // -----------------------------
        $ratings = [1 => 'Poor', 2 => 'Fair', 3 => 'Good', 4 => 'Very Good', 5 => 'Excellent'];

        $affectiveDomains = DB::table('user_has_affective_domains as uhd')
            ->join('affective_domains as ad', 'uhd.affective_id', '=', 'ad.id')
            ->where('uhd.user_id', $user->id)
            ->where('uhd.school_id', $schoolId)
            ->orderByDesc('uhd.updated_at')
            ->select('ad.title as domain', 'uhd.rate')
            ->get()
            ->unique('domain')
            ->values()
            ->map(fn ($r) => [
                'domain' => $r->domain,
                'rating' => $ratings[$r->rate] ?? 'Not Rated',
            ]);

        $psychomotorDomains = DB::table('user_has_psychomotor_domains as upd')
            ->join('psychomotor_domains as pd', 'upd.psychomotor_id', '=', 'pd.id')
            ->where('upd.user_id', $user->id)
            ->where('upd.school_id', $schoolId)
            ->orderByDesc('upd.updated_at')
            ->select('pd.title as domain', 'upd.rate')
            ->get()
            ->unique('domain')
            ->values()
            ->map(fn ($r) => [
                'domain' => $r->domain,
                'rating' => $ratings[$r->rate] ?? 'Not Rated',
            ]);

        // -----------------------------
        // 6️⃣ GRADING SYSTEM
        // -----------------------------
        $sectionName = optional($user->section)->name;
        if ($sectionName === 'Junior') {
            $grades = GradingForJunior::where('school_id', $schoolId)->get();
        } elseif ($sectionName === 'Senior') {
            $grades = GradingForSenior::where('school_id', $schoolId)->get();
        } else {
            $grades = [];
        }

        // -----------------------------
        // 7️⃣ RETURN RESULT
        // -----------------------------
        return [
            'studentId' => $user->id,
            'classId'   => $classId,
            'term'      => $term,
            'session'   => $session,
            'user'      => $user,
            'user_photo_base64' => $photoBase64,
            'class'     => $class,
            'term_result' => $termResult,
            'subjects'  => $subjects,
            'average'   => $average,
            'class_name' => $average->class->name ?? null,
            'class_size' => $average->class_size,
            'times_open' => $average->school_open,
            'present'   => $average->no_present,
            'absent'    => $average->no_absent,
            'grades'    => $grades,
            'school_info' => [
                'name' => $school->school_name,
                'phone' => $school->phone,
                'address' => $school->address,
                'logo' => $logoBase64,
                'principal_signature' => $signatureBase64,
                'backgroundColor' => $school->background_color,
                'secondaryColor' => $school->secondary_color,
                'primaryColor' => $school->primary_color,
            ],
            'affective_domains' => $affectiveDomains,
            'psychomotor_domains' => $psychomotorDomains,
        ];
    }
}
