<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\SchoolSetting;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class StudentAcademicRecordController extends Controller
{
    public function withdrawnStudents(Request $request): JsonResponse
    {
        $admin = $this->schoolAdmin($request);
        $search = trim((string) $request->query('search', ''));

        $students = User::query()
            ->forSchool((int) $admin->school_id)
            ->withRole('student')
            ->whereRaw('LOWER(COALESCE(student_status, ?)) = ?', ['active', 'withdrawn'])
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($nested) use ($search): void {
                    $nested->where('firstname', 'like', "%{$search}%")
                        ->orWhere('surname', 'like', "%{$search}%")
                        ->orWhere('third_name', 'like', "%{$search}%")
                        ->orWhere('reg_no', 'like', "%{$search}%");
                });
            })
            ->with(['level:id,name', 'section:id,name'])
            ->withCount(['studentResultsV2 as result_count'])
            ->orderByDesc('student_status_changed_at')
            ->paginate(min(max((int) $request->query('per_page', 15), 5), 50));

        $studentIds = collect($students->items())->pluck('id');
        $histories = $this->resultHistory((int) $admin->school_id, $studentIds->all(), false)
            ->groupBy('student_id');

        $students->getCollection()->transform(function (User $student) use ($histories) {
            $student->setAttribute('results', $histories->get($student->id, collect())->values());
            return $student;
        });

        return response()->json(['students' => $students]);
    }

    public function transcripts(Request $request): JsonResponse
    {
        $admin = $this->schoolAdmin($request);
        $search = trim((string) $request->query('search', ''));
        $status = strtolower(trim((string) $request->query('status', 'all')));
        $term = trim((string) $request->query('term', 'all'));

        $query = User::query()
            ->forSchool((int) $admin->school_id)
            ->withRole('student')
            ->when($search === '', fn ($q) => $q->whereRaw('1 = 0'))
            ->when($status !== 'all', fn ($q) => $q->whereRaw('LOWER(COALESCE(student_status, ?)) = ?', ['active', $status]))
            ->when($search !== '', fn ($q) => $q->where('reg_no', 'like', "%{$search}%"))
            ->with(['level:id,name'])
            ->withCount(['studentResultsV2 as published_result_count' => fn ($q) => $q->whereHas('batch', fn ($b) => $b
                ->where('status', 'published')
                ->when(strtolower($term) !== 'all' && $term !== '', fn ($batch) => $batch->where('term', $term)))])
            ->orderBy('surname')->orderBy('firstname');

        $students = $query->paginate(min(max((int) $request->query('per_page', 15), 5), 50));
        $ids = collect($students->items())->pluck('id');
        $exports = DB::table('student_transcript_exports')
            ->where('school_id', (int) $admin->school_id)
            ->whereIn('student_id', $ids)
            ->selectRaw('student_id, COUNT(*) as downloads, MAX(created_at) as last_downloaded_at')
            ->groupBy('student_id')->get()->keyBy('student_id');

        $students->getCollection()->transform(function (User $student) use ($exports) {
            $audit = $exports->get($student->id);
            $student->setAttribute('transcript_downloads', (int) ($audit->downloads ?? 0));
            $student->setAttribute('last_transcript_downloaded_at', $audit->last_downloaded_at ?? null);
            return $student;
        });

        $terms = DB::table('result_batches')->where('school_id', (int) $admin->school_id)
            ->where('status', 'published')->whereNotNull('term')->distinct()->orderBy('term')->pluck('term')->values();

        return response()->json(['students' => $students, 'terms' => $terms, 'selected_term' => $term ?: 'all']);
    }

    public function showTranscript(Request $request, User $student): JsonResponse
    {
        $admin = $this->schoolAdmin($request);
        $this->assertStudentInSchool($student, (int) $admin->school_id);

        $term = $this->requestedTerm($request);
        return response()->json($this->transcriptPayload($student, (int) $admin->school_id, $term));
    }

    public function downloadTranscript(Request $request, User $student): Response
    {
        $admin = $this->schoolAdmin($request);
        $this->assertStudentInSchool($student, (int) $admin->school_id);
        $term = $this->requestedTerm($request);
        $payload = $this->transcriptPayload($student, (int) $admin->school_id, $term);

        abort_if(count($payload['records']) === 0, 422, 'This student has no published results available for a transcript.');

        DB::table('student_transcript_exports')->insert([
            'school_id' => (int) $admin->school_id,
            'student_id' => $student->id,
            'downloaded_by' => $admin->id,
            'record_count' => count($payload['records']),
            'ip_address' => $request->ip(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $filename = 'transcript-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($student->reg_no ?: $student->id)) . '.pdf';

        $response = Pdf::loadView('pdf.student-transcript', $payload)
            ->setPaper('a4', 'portrait')
            ->download($filename);
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');

        return $response;
    }

    private function schoolAdmin(Request $request): User
    {
        $user = $request->user();
        abort_unless($user && strtolower(trim((string) $user->role)) === 'admin', 403, 'Only the school admin can access academic archives and transcripts.');
        return $user;
    }

    private function assertStudentInSchool(User $student, int $schoolId): void
    {
        abort_unless((int) $student->school_id === $schoolId && strtolower((string) $student->role) === 'student', 404);
    }

    private function transcriptPayload(User $student, int $schoolId, ?string $term = null): array
    {
        $student->loadMissing(['level:id,name', 'section:id,name']);
        $records = $this->resultHistory($schoolId, [$student->id], true)
            ->when($term, fn ($items) => $items->where('term', $term))->values();
        $school = SchoolSetting::query()->find($schoolId);
        $logoBase64 = $this->schoolLogoDataUri($school?->logo);

        return compact('student', 'records', 'school', 'term', 'logoBase64') + ['generated_at' => now()];
    }

    private function schoolLogoDataUri(?string $logo): ?string
    {
        if (! $logo) {
            return null;
        }

        $relativePath = ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $logo), DIRECTORY_SEPARATOR);
        $path = public_path($relativePath);
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $mime = mime_content_type($path) ?: 'image/png';
        return 'data:'.$mime.';base64,'.base64_encode(file_get_contents($path));
    }

    private function requestedTerm(Request $request): ?string
    {
        $term = trim((string) $request->query('term', 'all'));
        return $term === '' || strtolower($term) === 'all' ? null : $term;
    }

    private function resultHistory(int $schoolId, array $studentIds, bool $publishedOnly)
    {
        if (count($studentIds) === 0) {
            return collect();
        }

        $rows = DB::table('student_results_v2 as result')
            ->join('result_batches as batch', 'batch.id', '=', 'result.batch_id')
            ->leftJoin('student_classes as class', 'class.id', '=', 'batch.class_id')
            ->leftJoin('subject_results_v2 as subject_result', 'subject_result.student_result_id', '=', 'result.id')
            ->leftJoin('subjects as subject', 'subject.id', '=', 'subject_result.subject_id')
            ->where('batch.school_id', $schoolId)
            ->whereIn('result.user_id', $studentIds)
            ->when($publishedOnly, fn ($q) => $q->where('batch.status', 'published'))
            ->select([
                'result.id as result_id', 'result.user_id as student_id', 'batch.id as batch_id',
                'batch.session', 'batch.term', 'batch.status', 'batch.class_id', 'class.name as class_name',
                'result.total_average', 'result.total_grade', 'result.position',
                'subject_result.id as subject_result_id', 'subject.name as subject_name',
                'subject_result.ca_raw', 'subject_result.exam', 'subject_result.total',
                'subject_result.grade', 'subject_result.remark',
            ])
            ->orderByDesc('batch.session')->orderByRaw("CASE WHEN LOWER(batch.term) LIKE '%third%' THEN 3 WHEN LOWER(batch.term) LIKE '%second%' THEN 2 ELSE 1 END DESC")
            ->orderBy('subject.name')->get();

        return $rows->groupBy('result_id')->map(function ($resultRows) {
            $first = $resultRows->first();
            return [
                'result_id' => $first->result_id,
                'student_id' => $first->student_id,
                'batch_id' => $first->batch_id,
                'session' => $first->session,
                'term' => $first->term,
                'status' => $first->status,
                'class_id' => $first->class_id,
                'class_name' => $first->class_name,
                'average' => $first->total_average,
                'grade' => $first->total_grade,
                'position' => $first->position,
                'subjects' => $resultRows->filter(fn ($row) => $row->subject_result_id !== null)->map(fn ($row) => [
                    'name' => $row->subject_name,
                    'ca' => $this->formatCaScore($row->ca_raw),
                    'exam' => $row->exam,
                    'total' => $row->total,
                    'grade' => $row->grade,
                    'remark' => $row->remark,
                ])->values(),
            ];
        });
    }

    private function formatCaScore(mixed $raw): int|float|string|null
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        if (is_numeric($raw)) {
            return $raw + 0;
        }

        $decoded = json_decode((string) $raw, true);
        if (! is_array($decoded)) {
            return $raw;
        }

        $scores = collect($decoded)
            ->flatten()
            ->filter(fn ($score) => is_numeric($score));

        return $scores->isEmpty() ? null : $scores->sum();
    }
}
