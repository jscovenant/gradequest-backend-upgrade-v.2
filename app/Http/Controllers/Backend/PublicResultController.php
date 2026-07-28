<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

use App\Models\User;
use App\Models\Average;
use App\Models\ResultPin;
use App\Models\AcademicSession;
use App\Models\Term;
use App\Models\ResultTemplateSetting;

use App\Models\SchoolSetting;
use App\Models\StudentClass;

use App\Services\ResultService;
use App\Services\SchoolFeeAccessPolicyService;
use App\Traits\CheckFeeStatus;
use App\Repositories\ResultRepository;

use Symfony\Component\HttpKernel\Exception\HttpException;

class PublicResultController extends Controller
{
    use CheckFeeStatus;

    // ✅ IMPORTANT: this assumes you have a repo like in your ReportCard controller
    // If your reportCard() method lives in another controller that already has $this->repo,
    // move the private method below into that controller instead OR inject the repo here too.
    protected $repo;

    public function __construct(ResultRepository $repo, private SchoolFeeAccessPolicyService $feeAccessPolicyService)
    {
        $this->repo = $repo;
    }

    /**
     * GET /public/check-result?reg_no=...&pin=...
     */
    public function checkWithPin(Request $request)
    {
        $request->validate([
            'reg_no' => 'required|string',
            'pin'    => 'required|string',
        ]);

        // 1) Find student
        $student = User::where('reg_no', $request->reg_no)
            ->where('role', 'student')
            ->first();

        if (!$student) {
            return response()->json(['message' => 'Student not found'], 404);
        }

        // 2) Validate PIN. The PIN decides which term/session can be viewed.
        $pin = ResultPin::where('pin', $request->pin)
            ->where('is_active', true)
            ->where('school_id', $student->school_id)
            ->first();

        if (!$pin) {
            return response()->json([
                'message' => 'Invalid result PIN for this school'
            ], 403);
        }

        if (! empty($pin->student_id) && (int) $pin->student_id !== (int) $student->id) {
            return response()->json([
                'message' => 'This result PIN is not assigned to this student'
            ], 403);
        }

        // 4) Expiry check
        if ($pin->expires_at && now()->gt($pin->expires_at)) {
            return response()->json(['message' => 'Result PIN has expired'], 403);
        }

        // 5) Usage limit check
        if ($pin->used_count >= $pin->max_uses) {
            return response()->json(['message' => 'Result PIN usage exceeded'], 403);
        }

        // 6) School fee access policy
        $feeAccess = $this->feeAccessPolicyService->resultAccessStatus(
            (int) $student->school_id,
            (int) $student->id,
            (string) $pin->session,
            (string) $pin->term
        );
        if (! ($feeAccess['allowed'] ?? false)) {
            return response()->json([
                'status'  => 'fee_restricted',
                'message' => $feeAccess['message'],
                'fee_access' => $feeAccess,
            ], 403);
        }

        // 7) Context
        $term    = (string) $pin->term;
        $session = (string) $pin->session;
        $classId = (int) $student->level_id;

        // 8) Confirm result exists for current context (optional but matches your existing logic)
        $exists = Average::where([
            'user_id'  => $student->id,
            'class_id' => $classId,
            'term'     => $term,
            'session'  => $session,
        ])->exists() || $this->publishedV2ResultExists(
            (int) $student->school_id,
            $classId,
            $term,
            $session,
            (int) $student->id
        );

        if (!$exists) {
            return response()->json([
                'message' => "Result not available for {$term}, {$session}"
            ], 404);
        }

        // 9) increment usage
        $pin->increment('used_count');

        // 10) ✅ RETURN REPORT CARD PAYLOAD (v2-first, legacy fallback)
        return $this->buildReportCardPayload(
            schoolId: (int) $student->school_id,
            classId:  $classId,
            term:     $term,
            session:  $session,
            studentId:(int) $student->id
        );
    }

    /**
     * This is your reportCard() logic adapted to work in PublicResultController.
     */
    private function buildReportCardPayload(
        int $schoolId,
        int $classId,
        string $term,
        string $session,
        int $studentId
    ): JsonResponse {
        try {
            // Find batch
            $batch = $this->repo->findBatch($schoolId, $classId, $term, $session);

            // Load school (branding + theme)
            $school = SchoolSetting::findOrFail($schoolId);
            $templateSetting = ResultTemplateSetting::firstOrCreate(
                ['school_id' => $schoolId],
                ResultTemplateSetting::defaults($schoolId)
            )->normalized();

            // Try v2 if batch exists
            if ($batch) {
                if (($batch->status ?? null) !== 'published') {
                    return response()->json([
                        'message' => 'Result is not yet published. Please check back after the school releases it.',
                        'status' => 'not_published',
                    ], 403);
                }

                $sr = $this->repo->getV2StudentResult($batch->id, $studentId);

                if ($sr) {
                    $subjects = $this->repo->getV2SubjectResults($sr->id);

                    $student = User::findOrFail($studentId);
                    $class   = StudentClass::findOrFail($classId);

                    // student photo -> base64
                    $photoPath = $student->photo ? public_path('uploads/users/' . $student->photo) : null;
                    $photoBase64 = ($photoPath && file_exists($photoPath))
                        ? 'data:' . mime_content_type($photoPath) . ';base64,' . base64_encode(file_get_contents($photoPath))
                        : null;

                    $affective   = $this->repo->getAffectiveDomains($student->id, $schoolId);
                    $psychomotor = $this->repo->getPsychomotorDomains($student->id, $schoolId);

                    $meta = json_decode($sr->meta_json ?? '{}', true);

                    return response()->json([
                        'source' => 'v2',
                        'result_status' => $batch->status,
                        'user' => $student,
                        'user_photo_base64' => $photoBase64,
                        'average' => [
                            'id' => $sr->id,
                            'user_id' => $sr->user_id,
                            'class_id' => $class->id,
                            'term' => $term,
                            'session' => $session,
                            'total_average' => $sr->total_average ?? '0',
                            'total_grade' => $sr->total_grade ?? 'N/A',
                            'position' => $sr->position ?? 'N/A',
                            'class_size' => $sr->class_size ?? '0',
                            'no_present' => $meta['no_present'] ?? $meta['present'] ?? '0',
                            'no_absent' => $meta['no_absent'] ?? $meta['absent'] ?? '0',
                            'school_open' => $meta['school_open'] ?? $meta['times_open'] ?? '0',
                            'class_teacher_comment' => $sr->class_teacher_comment ?? '',
                            'principal_comment' => $sr->principal_comment ?? '',
                            'general_remark' => $sr->general_remark ?? '',
                            'resumption_date' => $meta['resumption_date'] ?? null,
                        ],
                        'term_result' => $subjects,
                        'class_name' => $class->name,
                        'class_section_id' => $class->section_id,
                        'class_section_name' => optional($class->section)->name,
                        'school_info' => [
                            'name' => $school->school_name,
                            'address' => $school->address,
                            'phone' => $school->phone,
                            'logo' => $this->getBase64Image($school->logo),
                            'principal_signature' => $this->getBase64Image($school->principal_signature),
                            'primary_color' => $school->primary_color ?? '#0d47a1',
                            'secondary_color' => $school->secondary_color ?? '#ffc107',
                            'background_color' => $school->background_color ?? '#ffffff',
                        ],
                        'result_template' => $templateSetting,
                        'affective_domains' => $affective,
                        'psychomotor_domains' => $psychomotor,
                    ]);
                }
            }

            // Fallback legacy
            $student = User::findOrFail($studentId);
            $legacy = app(ResultService::class)->build(
                user: $student,
                classId: $classId,
                term: $term,
                session: $session
            );

            $legacy['source'] = 'legacy';
            $legacy['school_info'] = is_array($legacy['school_info'] ?? null) ? $legacy['school_info'] : [];

            $legacy['school_info']['primary_color'] = $school->primary_color ?? '#0d47a1';
            $legacy['school_info']['secondary_color'] = $school->secondary_color ?? '#ffc107';
            $legacy['school_info']['background_color'] = $school->background_color ?? '#ffffff';

            if (empty($legacy['school_info']['logo'])) {
                $legacy['school_info']['logo'] = $this->getBase64Image($school->logo);
            }
            if (empty($legacy['school_info']['principal_signature'])) {
                $legacy['school_info']['principal_signature'] = $this->getBase64Image($school->principal_signature);
            }

            $legacy['school_info']['name'] = $legacy['school_info']['name'] ?? $school->school_name;
            $legacy['school_info']['address'] = $legacy['school_info']['address'] ?? $school->address;
            $legacy['school_info']['phone'] = $legacy['school_info']['phone'] ?? $school->phone;
            $legacy['result_template'] = $templateSetting;
            $legacyClass = StudentClass::with('section')->find($classId);
            $legacy['class_section_id'] = $legacyClass?->section_id;
            $legacy['class_section_name'] = $legacyClass?->section?->name;

            return response()->json($legacy);

        } catch (\Exception $e) {
            Log::error('Public report card error: ' . $e->getMessage());

            return response()->json([
                'message' => 'Result not found: ' . $e->getMessage()
            ], 404);
        }
    }

    /**
     * Your existing helper (you already have this in your other controller).
     * Make sure it exists here too, or move this controller to extend the one that has it.
     */
private function getBase64Image($path)
{
    if (!$path || !file_exists(public_path($path))) {
        return null;
    }
    
    return 'data:' . mime_content_type(public_path($path)) . ';base64,' 
           . base64_encode(file_get_contents(public_path($path)));
}

private function publishedV2ResultExists(int $schoolId, int $classId, string $term, string $session, int $studentId): bool
{
    return DB::table('result_batches as b')
        ->join('student_results_v2 as sr', 'sr.batch_id', '=', 'b.id')
        ->where('b.school_id', $schoolId)
        ->where('b.class_id', $classId)
        ->where('b.term', $term)
        ->where('b.session', $session)
        ->where('b.status', 'published')
        ->where('sr.user_id', $studentId)
        ->exists();
}
}
