<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class GradeQuestInternationalSchoolDemoSeeder extends Seeder
{
    public function run(): void
    {
        $school = DB::table('school_settings')
            ->whereRaw("LOWER(REPLACE(REPLACE(TRIM(school_name), ' ', ''), '-', '')) IN (?, ?)", ['gradequestinternationalschool', 'gradequstinternationalschool'])
            ->first();

        if (! $school) {
            $available = DB::table('school_settings')->orderBy('id')->pluck('school_name', 'id')->all();
            throw new RuntimeException('GradeQuest International School was not found. Available schools: '.json_encode($available).'. No data was inserted.');
        }

        $schoolId = (int) $school->id;
        $classes = DB::table('student_classes')->where('school_id', $schoolId)
            ->when(Schema::hasColumn('student_classes', 'archived_at'), fn ($q) => $q->whereNull('archived_at'))
            ->orderBy('id')->get();
        $subjects = DB::table('subjects')->where('school_id', $schoolId)
            ->when(Schema::hasColumn('subjects', 'archived_at'), fn ($q) => $q->whereNull('archived_at'))
            ->orderBy('id')->get();

        $sessionQuery = DB::table('academic_sessions');
        if (Schema::hasColumn('academic_sessions', 'school_id')) $sessionQuery->where('school_id', $schoolId);
        if (Schema::hasColumn('academic_sessions', 'archived_at')) $sessionQuery->whereNull('archived_at');
        if (Schema::hasColumn('academic_sessions', 'is_current')) $sessionQuery->orderByDesc('is_current');
        $session = $sessionQuery->orderByDesc('id')->first();

        $termQuery = DB::table('terms')->where('school_id', $schoolId);
        if (Schema::hasColumn('terms', 'archived_at')) $termQuery->whereNull('archived_at');
        if (Schema::hasColumn('terms', 'status')) $termQuery->orderByRaw("CASE WHEN LOWER(status) = 'active' THEN 0 ELSE 1 END");
        $term = $termQuery->orderBy('sort_order')->orderBy('id')->first();

        if ($classes->isEmpty() || $subjects->isEmpty() || ! $session || ! $term) {
            throw new RuntimeException('The target school needs at least one class, one subject, one academic session, and one term. No data was inserted.');
        }

        $firstNames = ['Ada', 'Chidi', 'Zainab', 'Tobi', 'Amara', 'David', 'Aisha', 'Daniel', 'Grace', 'Samuel'];
        $surnames = ['Okafor', 'Adeyemi', 'Bello', 'Eze', 'Williams', 'Balogun', 'Ibrahim', 'Ogunleye', 'Nwosu', 'Lawal'];
        $teacherFirstNames = ['Ifeoma', 'Kunle', 'Fatima', 'Emeka', 'Bola', 'Mary', 'Hassan', 'Esther', 'Joseph', 'Blessing'];

        DB::transaction(function () use ($schoolId, $classes, $subjects, $session, $term, $firstNames, $surnames, $teacherFirstNames): void {
            for ($index = 1; $index <= 10; $index++) {
                $class = $classes[($index - 1) % $classes->count()];
                $studentCode = sprintf('GQDEMO-S%02d', $index);
                $teacherCode = sprintf('GQDEMO-T%02d', $index);
                $now = now();

                $studentId = DB::table('users')->where('school_id', $schoolId)->where('reg_no', $studentCode)->value('id');
                $studentPayload = $this->userPayload(
                    $schoolId, $studentCode, $firstNames[$index - 1], $surnames[$index - 1], 'Student',
                    (int) $class->id, $index % 2 === 0 ? 'Female' : 'Male'
                );
                if ($studentId) DB::table('users')->where('id', $studentId)->update($studentPayload + ['updated_at' => $now]);
                else $studentId = DB::table('users')->insertGetId($studentPayload + ['created_at' => $now, 'updated_at' => $now]);

                $teacherId = DB::table('users')->where('school_id', $schoolId)->where('username', strtolower($teacherCode))->value('id');
                $teacherPayload = $this->userPayload(
                    $schoolId, null, $teacherFirstNames[$index - 1], $surnames[9 - ($index - 1)], 'Teacher',
                    null, $index % 2 === 0 ? 'Male' : 'Female', strtolower($teacherCode)
                );
                if ($teacherId) DB::table('users')->where('id', $teacherId)->update($teacherPayload + ['updated_at' => $now]);
                else $teacherId = DB::table('users')->insertGetId($teacherPayload + ['created_at' => $now, 'updated_at' => $now]);

                $enrollmentKey = ['user_id' => $teacherId, 'level_id' => (int) $class->id];
                $enrollmentValues = ['enroll' => 1, 'created_at' => $now, 'updated_at' => $now];
                if (Schema::hasColumn('teacher_enrollments', 'school_id')) $enrollmentValues['school_id'] = $schoolId;
                if (Schema::hasColumn('teacher_enrollments', 'teacher_id')) $enrollmentValues['teacher_id'] = $teacherId;
                DB::table('teacher_enrollments')->updateOrInsert($enrollmentKey, $enrollmentValues);

                $batchId = DB::table('result_batches')->where([
                    'school_id' => $schoolId, 'class_id' => (int) $class->id,
                    'term' => $term->name, 'session' => $session->name,
                ])->value('id');
                if (! $batchId) {
                    $batchId = DB::table('result_batches')->insertGetId([
                        'school_id' => $schoolId, 'class_id' => (int) $class->id,
                        'term' => $term->name, 'session' => $session->name,
                        'status' => 'published', 'created_at' => $now, 'updated_at' => $now,
                    ]);
                } else {
                    DB::table('result_batches')->where('id', $batchId)->update(['status' => 'published', 'updated_at' => $now]);
                }

                $studentResultId = DB::table('student_results_v2')->where(['batch_id' => $batchId, 'user_id' => $studentId])->value('id');
                $average = 64 + ($index * 2.1);
                $summary = [
                    'rollno' => $studentCode, 'position' => (string) $index,
                    'class_size' => '10', 'total_average' => number_format($average, 2, '.', ''),
                    'total_grade' => $average >= 75 ? 'A' : ($average >= 65 ? 'B' : 'C'),
                    'class_teacher' => $teacherFirstNames[$index - 1].' '.$surnames[9 - ($index - 1)],
                    'principal_comment' => 'A promising performance. Keep improving.',
                    'class_teacher_comment' => 'Good effort and consistent participation.',
                    'general_remark' => 'Promoted', 'updated_at' => $now,
                ];
                if ($studentResultId) DB::table('student_results_v2')->where('id', $studentResultId)->update($summary);
                else $studentResultId = DB::table('student_results_v2')->insertGetId($summary + ['batch_id' => $batchId, 'user_id' => $studentId, 'created_at' => $now]);

                $classSubjects = $subjects->filter(fn ($subject) => ! isset($subject->class_id) || ! $subject->class_id || (int) $subject->class_id === (int) $class->id);
                if ($classSubjects->isEmpty()) $classSubjects = $subjects;

                foreach ($classSubjects->take(8)->values() as $subjectIndex => $subject) {
                    $ca = 24 + (($index + $subjectIndex) % 7);
                    $exam = 42 + (($index * 2 + $subjectIndex) % 17);
                    $total = min(100, $ca + $exam);
                    $grade = $total >= 75 ? 'A' : ($total >= 65 ? 'B' : ($total >= 50 ? 'C' : 'D'));
                    DB::table('subject_results_v2')->updateOrInsert(
                        ['student_result_id' => $studentResultId, 'subject_id' => (int) $subject->id],
                        ['ca_raw' => json_encode(['CA' => $ca]), 'exam' => $exam, 'total' => $total,
                         'grade' => $grade, 'remark' => $total >= 50 ? 'Pass' : 'Needs improvement',
                         'created_at' => $now, 'updated_at' => $now]
                    );
                }
            }
        });

        $studentIds = DB::table('users')->where('school_id', $schoolId)->where('reg_no', 'like', 'GQDEMO-S%')->pluck('id');
        $teacherIds = DB::table('users')->where('school_id', $schoolId)->where('username', 'like', 'gqdemo-t%')->pluck('id');
        $assignmentCount = DB::table('teacher_enrollments')->whereIn('user_id', $teacherIds)->where('enroll', 1)->count();
        $resultCount = DB::table('student_results_v2')->whereIn('user_id', $studentIds)->count();
        $subjectResultCount = DB::table('subject_results_v2')->whereIn('student_result_id', function ($query) use ($studentIds): void {
            $query->select('id')->from('student_results_v2')->whereIn('user_id', $studentIds);
        })->count();

        $this->command?->info(sprintf(
            'Verified school %d: %d demo students, %d demo teachers, %d class assignments, %d student results, %d subject scores.',
            $schoolId, $studentIds->count(), $teacherIds->count(), $assignmentCount, $resultCount, $subjectResultCount
        ));
    }

    private function userPayload(int $schoolId, ?string $regNo, string $firstName, string $surname, string $role, ?int $levelId, string $sex, ?string $username = null): array
    {
        $identity = strtolower($username ?: $regNo);
        $payload = [
            'school_id' => $schoolId, 'name' => "$firstName $surname", 'firstname' => $firstName,
            'surname' => $surname, 'email' => "$identity@demo.gradequest.local", 'username' => $identity,
            'reg_no' => $regNo, 'password' => Hash::make('GradeQuest@123'), 'role' => $role,
            'status' => 1, 'level_id' => $levelId, 'sex' => $sex,
            'student_status' => 'active',
            'teacher_status' => 'active',
        ];

        return array_intersect_key($payload, array_flip(Schema::getColumnListing('users')));
    }
}
