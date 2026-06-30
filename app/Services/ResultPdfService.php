<?php


// app/Services/ResultPdfService.php

namespace App\Services;

use App\Models\{
    User, Average, Subject, SchoolSetting,
    FirstTermResult, SecondTermResult, ThirdTermResult,
    StudentClass, GradingForJunior, GradingForSenior
};
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;

class ResultPdfService
{
    public function generate(int $studentId, int $classId, string $term, string $session): ?string
    {
        // Load student
        $user = User::with('level', 'section')->find($studentId);
        if (!$user) return null;

        // Load average
        $average = Average::where('user_id', $user->id)
            ->where('class_id', $classId)
            ->where('term', $term)
            ->where('session', $session)
            ->first();

        if (!$average) return null;

        // Subjects
        $subjects = Subject::where('school_id', $user->school_id)
            ->where('class_id', $classId)
            ->get();

        // Term result model
        $termModelMap = [
            'First Term'  => FirstTermResult::class,
            'Second Term' => SecondTermResult::class,
            'Third Term'  => ThirdTermResult::class,
        ];

        if (!array_key_exists($term, $termModelMap)) return null;

        $termResult = $termModelMap[$term]::where('user_id', $user->id)
            ->where('average_id', $average->id)
            ->where('class_id', $classId)
            ->with('subject')
            ->get();

        // School info
        $school_info = SchoolSetting::find($user->school_id);

        // Base64 assets
        $logoBase64      = $this->toBase64(public_path($school_info->logo ?? ''));
        $userPhotoBase64 = $this->toBase64(public_path('uploads/users/' . ($user->photo ?? '')));
        $principalSigBase64 = $this->toBase64(public_path($school_info->principal_signature ?? ''));

        // Ratings
        $ratings = [1 => 'Poor', 2 => 'Good', 3 => 'Very Good', 4 => 'Excellent'];

        $affectiveDomains = DB::table('user_has_affective_domains as uhd')
            ->join('affective_domains as ad', 'uhd.affective_id', '=', 'ad.id')
            ->where('uhd.user_id', $user->id)
            ->where('uhd.school_id', $user->school_id)
            ->orderBy('uhd.updated_at', 'DESC')
            ->select('ad.title as domain', 'uhd.rate')
            ->get()->unique('domain')->values()
            ->map(fn($r) => ['domain' => $r->domain, 'rating' => $ratings[$r->rate] ?? 'Not Rated']);

        $psychomotorDomains = DB::table('user_has_psychomotor_domains as upd')
            ->join('psychomotor_domains as pd', 'upd.psychomotor_id', '=', 'pd.id')
            ->where('upd.user_id', $user->id)
            ->where('upd.school_id', $user->school_id)
            ->orderBy('upd.updated_at', 'DESC')
            ->select('pd.title as domain', 'upd.rate')
            ->get()->unique('domain')->values()
            ->map(fn($r) => ['domain' => $r->domain, 'rating' => $ratings[$r->rate] ?? 'Not Rated']);

        $sectionName = optional($user->section)->name;
        $grades = match($sectionName) {
            'Junior' => GradingForJunior::where('school_id', $user->school_id)->get(),
            'Senior' => GradingForSenior::where('school_id', $user->school_id)->get(),
            default  => collect(),
        };

        // Generate PDF from blade view
        $pdf = Pdf::loadView('pdf.student-result', [
            'term'                => $term,
            'user'                => $user,
            'user_photo_base64'   => $userPhotoBase64,
            'class_name'          => optional($average->class)->name,
            'term_result'         => $termResult,
            'average'             => $average,
            'grades'              => $grades,
            'affective_domains'   => $affectiveDomains,
            'psychomotor_domains' => $psychomotorDomains,
            'school_info'         => $school_info,
            'logo_base64'         => $logoBase64,
            'principal_sig_base64'=> $principalSigBase64,
        ])->setPaper('a4', 'portrait');

        // Save to temp storage
        $filename = "result_{$studentId}_{$term}_{$session}.pdf";
        $path     = storage_path("app/temp/{$filename}");

        if (!file_exists(storage_path('app/temp'))) {
            mkdir(storage_path('app/temp'), 0755, true);
        }

        $pdf->save($path);

        return $path;
    }

    private function toBase64(string $path): ?string
    {
        if (!$path || !file_exists($path)) return null;
        $mime = mime_content_type($path);
        return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($path));
    }
}