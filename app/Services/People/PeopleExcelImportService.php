<?php

namespace App\Services\People;

use App\Exports\ResultTemplateExport;
use App\Imports\RawSheetImport;
use App\Models\SchoolSetting;
use App\Models\StudentClass;
use App\Models\TeacherEnrollment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;

class PeopleExcelImportService
{
    private const TEACHER_HEADINGS = [
        'firstname',
        'surname',
        'email',
        'phone',
        'gender',
        'class',
        'dob',
        'address',
    ];

    private const PARENT_HEADINGS = [
        'firstname',
        'surname',
        'email',
        'phone',
        'address',
        'student_admission_numbers',
    ];

    public function teacherTemplate(int $schoolId, string $format = 'xlsx')
    {
        $class = StudentClass::where('school_id', $schoolId)->whereNull('archived_at')->orderBy('name')->value('name') ?? 'JSS1';

        return $this->downloadTemplate(
            self::TEACHER_HEADINGS,
            [['John', 'Adeyemi', 'john.teacher@example.com', '08030000001', 'Male', $class, '1990-01-10', '15 School Road']],
            'teacher_upload_template',
            $format
        );
    }

    public function parentTemplate(int $schoolId, string $format = 'xlsx')
    {
        $studentNos = User::where('school_id', $schoolId)
            ->whereRaw('LOWER(role) = ?', ['student'])
            ->orderBy('surname')
            ->limit(2)
            ->pluck('reg_no')
            ->filter()
            ->implode(', ');

        return $this->downloadTemplate(
            self::PARENT_HEADINGS,
            [['Grace', 'Bello', 'grace.parent@example.com', '08030000002', '12 Parent Street', $studentNos]],
            'parent_upload_template',
            $format
        );
    }

    public function previewTeachers(User $admin, UploadedFile $file): array
    {
        $schoolId = (int) $admin->school_id;
        $classes = StudentClass::where('school_id', $schoolId)->whereNull('archived_at')->get();
        $mappedRows = $this->mapRows($this->readRows($file));
        $errors = [];
        $warnings = [];
        $rows = [];
        $seenEmails = [];
        $seenPhones = [];

        foreach ($mappedRows as $index => $row) {
            $rowNumber = $index + 2;
            $rowErrors = [];
            $rowWarnings = [];

            $firstname = trim((string) ($row['firstname'] ?? ''));
            $surname = trim((string) ($row['surname'] ?? ''));
            $email = strtolower(trim((string) ($row['email'] ?? '')));
            $phone = trim((string) ($row['phone'] ?? ''));
            $gender = $this->normalizeGender($row['gender'] ?? $row['sex'] ?? '');
            $class = $this->resolveSetup($classes, $row['class'] ?? $row['level'] ?? null);

            if ($firstname === '') $rowErrors[] = 'Firstname is required.';
            if ($surname === '') $rowErrors[] = 'Surname is required.';
            if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) $rowErrors[] = "Email {$email} is not valid.";
            if ($email !== '' && isset($seenEmails[$email])) $rowErrors[] = "Email {$email} appears more than once in this file.";
            if ($email !== '' && User::where('email', $email)->exists()) $rowErrors[] = "Email {$email} already exists.";
            if ($phone !== '' && isset($seenPhones[$phone])) $rowErrors[] = "Phone {$phone} appears more than once in this file.";
            if ($phone !== '' && User::where('phone', $phone)->exists()) $rowErrors[] = "Phone {$phone} already exists.";
            if (! $class) $rowErrors[] = 'Class was not found. Use class name or ID exactly as saved.';
            if ($gender === '') $rowWarnings[] = 'Gender is empty.';

            if ($email !== '') $seenEmails[$email] = true;
            if ($phone !== '') $seenPhones[$phone] = true;

            if ($firstname !== '' && $surname !== '' && User::where('school_id', $schoolId)
                ->whereRaw('LOWER(role) = ?', ['teacher'])
                ->where('firstname', $firstname)
                ->where('surname', $surname)
                ->exists()) {
                $rowErrors[] = 'A teacher with this same name already exists.';
            }

            $rows[] = [
                'row' => $rowNumber,
                'firstname' => $firstname,
                'surname' => $surname,
                'email' => $email,
                'phone' => $phone,
                'gender' => $gender,
                'class_id' => $class?->id,
                'class_name' => $class?->name,
                'dob' => trim((string) ($row['dob'] ?? '')),
                'address' => trim((string) ($row['address'] ?? '')),
                'status' => empty($rowErrors) ? 'ready' : 'error',
                'errors' => $rowErrors,
                'warnings' => $rowWarnings,
            ];

            foreach ($rowErrors as $error) $errors[] = "Row {$rowNumber}: {$error}";
            foreach ($rowWarnings as $warning) $warnings[] = "Row {$rowNumber}: {$warning}";
        }

        return $this->previewResponse($rows, $errors, $warnings);
    }

    public function importTeachers(User $admin, UploadedFile $file): array
    {
        $preview = $this->previewTeachers($admin, $file);
        if (! ($preview['summary']['can_import'] ?? false)) {
            return ['imported' => 0, 'preview' => $preview, 'message' => 'Import was not completed because the file still has errors.'];
        }

        $schoolId = (int) $admin->school_id;
        $settings = SchoolSetting::where('id', $schoolId)->first();
        $created = [];

        DB::transaction(function () use ($preview, $schoolId, $settings, &$created) {
            foreach (collect($preview['rows'])->where('status', 'ready') as $row) {
                $password = Str::random(10);
                $regNo = $this->generateRegNo($settings);

                $teacher = User::create([
                    'firstname' => $row['firstname'],
                    'surname' => $row['surname'],
                    'email' => $row['email'] ?: null,
                    'phone' => $row['phone'] ?: null,
                    'sex' => $row['gender'] ?: null,
                    'dob' => $row['dob'] ?: null,
                    'address' => $row['address'] ?: null,
                    'username' => $regNo,
                    'reg_no' => $regNo,
                    'role' => 'Teacher',
                    'school_id' => $schoolId,
                    'status' => 1,
                    'teacher_status' => 'active',
                    'password' => Hash::make($password),
                    'default_password' => encrypt($password),
                ]);

                $teacher->assignRole('Teacher');

                TeacherEnrollment::updateOrCreate(
                    ['user_id' => $teacher->id],
                    ['level_id' => $row['class_id'], 'school_id' => $schoolId, 'enroll' => true]
                );

                $created[] = [
                    'id' => $teacher->id,
                    'name' => trim($teacher->firstname . ' ' . $teacher->surname),
                    'reg_no' => $teacher->reg_no,
                    'default_password' => $password,
                ];
            }
        });

        return ['imported' => count($created), 'teachers' => $created, 'message' => count($created) . ' teacher(s) imported successfully.'];
    }

    public function previewParents(User $admin, UploadedFile $file): array
    {
        $schoolId = (int) $admin->school_id;
        $mappedRows = $this->mapRows($this->readRows($file));
        $errors = [];
        $warnings = [];
        $rows = [];
        $seenEmails = [];
        $seenPhones = [];
        $seenStudentNos = [];

        foreach ($mappedRows as $index => $row) {
            $rowNumber = $index + 2;
            $rowErrors = [];
            $rowWarnings = [];

            $firstname = trim((string) ($row['firstname'] ?? ''));
            $surname = trim((string) ($row['surname'] ?? ''));
            $email = strtolower(trim((string) ($row['email'] ?? '')));
            $phone = trim((string) ($row['phone'] ?? ''));
            $studentNos = $this->parseList($row['student_admission_numbers'] ?? $row['children'] ?? $row['student_reg_no'] ?? '');

            if ($firstname === '') $rowErrors[] = 'Firstname is required.';
            if ($surname === '') $rowErrors[] = 'Surname is required.';
            if ($email === '') $rowErrors[] = 'Email is required.';
            if ($phone === '') $rowErrors[] = 'Phone is required.';
            if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) $rowErrors[] = "Email {$email} is not valid.";
            if ($email !== '' && isset($seenEmails[$email])) $rowErrors[] = "Email {$email} appears more than once in this file.";
            if ($phone !== '' && isset($seenPhones[$phone])) $rowErrors[] = "Phone {$phone} appears more than once in this file.";
            if ($email !== '' && User::where('email', $email)->exists()) $rowErrors[] = "Email {$email} already exists.";
            if ($phone !== '' && User::where('phone', $phone)->exists()) $rowErrors[] = "Phone {$phone} already exists.";

            if ($email !== '') $seenEmails[$email] = true;
            if ($phone !== '') $seenPhones[$phone] = true;

            $studentIds = [];
            foreach ($studentNos as $studentNo) {
                $studentKey = strtolower($studentNo);
                if (isset($seenStudentNos[$studentKey])) {
                    $rowErrors[] = "Student {$studentNo} appears under more than one parent in this file.";
                    continue;
                }
                $seenStudentNos[$studentKey] = true;

                $student = User::where('school_id', $schoolId)
                    ->whereRaw('LOWER(role) = ?', ['student'])
                    ->where('reg_no', $studentNo)
                    ->first();

                if (! $student) {
                    $rowErrors[] = "Student admission number {$studentNo} was not found.";
                    continue;
                }

                $existingParent = DB::table('parent_students as ps')
                    ->join('users as p', 'p.id', '=', 'ps.parent_id')
                    ->where('ps.school_id', $schoolId)
                    ->where('ps.student_id', $student->id)
                    ->select('p.firstname', 'p.surname')
                    ->first();

                if ($existingParent) {
                    $rowErrors[] = "Student {$studentNo} is already assigned to {$existingParent->firstname} {$existingParent->surname}.";
                    continue;
                }

                $studentIds[] = (int) $student->id;
            }

            if (empty($studentNos)) $rowWarnings[] = 'No child admission number was supplied. Parent will be created without linked children.';

            $rows[] = [
                'row' => $rowNumber,
                'firstname' => $firstname,
                'surname' => $surname,
                'email' => $email,
                'phone' => $phone,
                'address' => trim((string) ($row['address'] ?? '')),
                'student_admission_numbers' => $studentNos,
                'student_ids' => $studentIds,
                'status' => empty($rowErrors) ? 'ready' : 'error',
                'errors' => $rowErrors,
                'warnings' => $rowWarnings,
            ];

            foreach ($rowErrors as $error) $errors[] = "Row {$rowNumber}: {$error}";
            foreach ($rowWarnings as $warning) $warnings[] = "Row {$rowNumber}: {$warning}";
        }

        return $this->previewResponse($rows, $errors, $warnings);
    }

    public function importParents(User $admin, UploadedFile $file): array
    {
        $preview = $this->previewParents($admin, $file);
        if (! ($preview['summary']['can_import'] ?? false)) {
            return ['imported' => 0, 'preview' => $preview, 'message' => 'Import was not completed because the file still has errors.'];
        }

        $schoolId = (int) $admin->school_id;
        $created = [];

        DB::transaction(function () use ($preview, $schoolId, &$created) {
            foreach (collect($preview['rows'])->where('status', 'ready') as $row) {
                $password = Str::random(8);

                $parent = User::create([
                    'firstname' => $row['firstname'],
                    'surname' => $row['surname'],
                    'email' => $row['email'],
                    'phone' => $row['phone'],
                    'address' => $row['address'] ?: null,
                    'role' => 'Parent',
                    'school_id' => $schoolId,
                    'status' => 1,
                    'password' => Hash::make($password),
                    'default_password' => encrypt($password),
                ]);

                $parent->assignRole('Parent');

                foreach ($row['student_ids'] as $studentId) {
                    DB::table('parent_students')->updateOrInsert(
                        ['school_id' => $schoolId, 'student_id' => $studentId],
                        ['parent_id' => $parent->id, 'created_at' => now(), 'updated_at' => now()]
                    );
                }

                $created[] = [
                    'id' => $parent->id,
                    'name' => trim($parent->firstname . ' ' . $parent->surname),
                    'email' => $parent->email,
                    'children_count' => count($row['student_ids']),
                    'default_password' => $password,
                ];
            }
        });

        return ['imported' => count($created), 'parents' => $created, 'message' => count($created) . ' parent(s) imported successfully.'];
    }

    private function downloadTemplate(array $headers, array $rows, string $baseName, string $format)
    {
        $extension = strtolower($format) === 'csv' ? 'csv' : (strtolower($format) === 'xls' ? 'xls' : 'xlsx');
        $writerType = match ($extension) {
            'csv' => ExcelFormat::CSV,
            'xls' => ExcelFormat::XLS,
            default => ExcelFormat::XLSX,
        };

        return Excel::download(new ResultTemplateExport($headers, $rows), $baseName . '.' . $extension, $writerType);
    }

    private function readRows(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, ['csv', 'txt', 'xls', 'xlsx'], true)) {
            throw new \InvalidArgumentException('Upload a valid Excel or CSV file. Accepted formats are .xlsx, .xls, and .csv.');
        }

        if (in_array($extension, ['csv', 'txt'], true)) {
            return array_map('str_getcsv', file($file->getRealPath()) ?: []);
        }

        $import = new RawSheetImport();
        $sheets = Excel::toArray($import, $file);
        return $sheets[0] ?? [];
    }

    private function mapRows(array $rows): array
    {
        if (empty($rows)) {
            throw new \InvalidArgumentException('The uploaded file is empty.');
        }

        $headers = array_map(fn ($header) => $this->normalizeHeader($header), $rows[0] ?? []);
        $mapped = [];

        foreach (array_slice($rows, 1) as $row) {
            if ($this->isEmptyRow($row)) {
                continue;
            }

            $assoc = [];
            foreach ($headers as $index => $header) {
                if ($header !== '') {
                    $assoc[$header] = $row[$index] ?? null;
                }
            }
            $mapped[] = $assoc;
        }

        return $mapped;
    }

    private function previewResponse(array $rows, array $errors, array $warnings): array
    {
        $ready = collect($rows)->where('status', 'ready')->count();

        return [
            'summary' => [
                'total_rows' => count($rows),
                'ready_rows' => $ready,
                'errors_count' => count($errors),
                'warnings_count' => count($warnings),
                'can_import' => count($errors) === 0 && $ready > 0,
            ],
            'rows' => $rows,
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    private function resolveSetup($items, mixed $value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        return $items->first(fn ($item) => (string) $item->id === $value)
            ?: $items->first(fn ($item) => strtolower((string) $item->name) === strtolower($value));
    }

    private function normalizeGender(mixed $value): string
    {
        $value = strtolower(trim((string) $value));
        return match ($value) {
            'm', 'male' => 'Male',
            'f', 'female' => 'Female',
            default => '',
        };
    }

    private function parseList(mixed $value): array
    {
        return collect(preg_split('/[,;\n|]+/', (string) $value) ?: [])
            ->map(fn ($item) => trim($item))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function generateRegNo(?SchoolSetting $settings): string
    {
        $prefix = $settings?->prefix ?: 'GQ';

        do {
            $regNo = $prefix . random_int(100000, 999999);
        } while (User::where('reg_no', $regNo)->exists());

        return $regNo;
    }

    private function normalizeHeader(mixed $value): string
    {
        $value = strtolower(trim((string) $value));
        $value = str_replace(
            ['admission number', 'admission no', 'student admission numbers', 'student admission nos', 'student reg no', 'children admission numbers', 'middle name'],
            ['student_admission_numbers', 'student_admission_numbers', 'student_admission_numbers', 'student_admission_numbers', 'student_admission_numbers', 'student_admission_numbers', 'third_name'],
            $value
        );
        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?: '';
        return trim($value, '_');
    }

    private function isEmptyRow(array $row): bool
    {
        return collect($row)->filter(fn ($value) => trim((string) $value) !== '')->isEmpty();
    }
}
