<?php

namespace App\Services\Students;

use App\Exports\ResultTemplateExport;
use App\Imports\RawSheetImport;
use App\Models\Department;
use App\Models\SchoolSetting;
use App\Models\Section;
use App\Models\StudentClass;
use App\Models\User;
use App\Exceptions\SubscriptionLimitExceededException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;

class StudentExcelImportService
{
    private const HEADINGS = [
        'firstname',
        'surname',
        'third_name',
        'gender',
        'admission_no',
        'class',
        'section',
        'department',
        'email',
        'phone',
        'dob',
        'address',
        'blood_group',
        'religion',
        'nationality',
    ];

    public function template(int $schoolId, string $format = 'xlsx')
    {
        $class = StudentClass::where('school_id', $schoolId)->whereNull('archived_at')->orderBy('name')->value('name') ?? 'JSS1';
        $section = Section::where('school_id', $schoolId)->whereNull('archived_at')->orderBy('name')->value('name') ?? 'Junior';
        $department = Department::where('school_id', $schoolId)->whereNull('archived_at')->orderBy('name')->value('name') ?? 'General';

        $rows = [[
            'Amina',
            'Bello',
            'Grace',
            'Female',
            '',
            $class,
            $section,
            $department,
            'amina@example.com',
            '08030000000',
            '2014-09-12',
            '12 School Road',
            'O+',
            'Christianity',
            'Nigeria',
        ]];

        $writerType = match (strtolower($format)) {
            'csv' => ExcelFormat::CSV,
            'xls' => ExcelFormat::XLS,
            default => ExcelFormat::XLSX,
        };

        return Excel::download(
            new ResultTemplateExport(self::HEADINGS, $rows),
            'student_upload_template.' . strtolower($format),
            $writerType
        );
    }

    public function preview(User $admin, UploadedFile $file): array
    {
        $schoolId = (int) $admin->school_id;
        $rows = $this->readRows($file);
        $mappedRows = $this->mapRows($rows);
        $errors = [];
        $warnings = [];
        $readyRows = [];
        $seenAdmissionNumbers = [];
        $seenNames = [];

        $classes = StudentClass::where('school_id', $schoolId)->whereNull('archived_at')->get();
        $sections = Section::where('school_id', $schoolId)->whereNull('archived_at')->get();
        $departments = Department::where('school_id', $schoolId)->whereNull('archived_at')->get();
        $settings = SchoolSetting::where('id', $schoolId)->first();
        $autoAdmission = (int) ($settings?->auto_admission ?? 0) === 1;

        foreach ($mappedRows as $index => $row) {
            $rowNumber = $index + 2;
            $rowErrors = [];
            $rowWarnings = [];

            $firstname = trim((string) ($row['firstname'] ?? ''));
            $surname = trim((string) ($row['surname'] ?? ''));
            $thirdName = trim((string) ($row['third_name'] ?? ''));
            $gender = $this->normalizeGender($row['gender'] ?? '');
            $admissionNo = strtoupper(trim((string) ($row['admission_no'] ?? '')));
            $email = trim((string) ($row['email'] ?? ''));

            if ($firstname === '') $rowErrors[] = 'Firstname is required.';
            if ($surname === '') $rowErrors[] = 'Surname is required.';
            if ($thirdName === '') $rowWarnings[] = 'Middle name is empty.';
            if (! in_array($gender, ['Male', 'Female'], true)) $rowErrors[] = 'Gender must be Male or Female.';
            if (! $autoAdmission && $admissionNo === '') $rowErrors[] = 'Admission number is required because auto admission is disabled.';
            if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) $rowErrors[] = "Email {$email} is not valid.";
            if ($email !== '' && User::where('email', $email)->exists()) $rowErrors[] = "Email {$email} already exists.";

            if ($admissionNo !== '') {
                if (isset($seenAdmissionNumbers[$admissionNo])) {
                    $rowErrors[] = "Admission number {$admissionNo} appears more than once in this file.";
                }
                $seenAdmissionNumbers[$admissionNo] = true;

                if (User::where('reg_no', $admissionNo)->exists()) {
                    $rowErrors[] = "Admission number {$admissionNo} already exists.";
                }
            }

            $nameKey = strtolower($firstname . '|' . $surname . '|' . $thirdName);
            if ($firstname !== '' && $surname !== '') {
                if (isset($seenNames[$nameKey])) {
                    $rowWarnings[] = 'A student with this same full name appears more than once in this file.';
                }
                $seenNames[$nameKey] = true;

                if (User::where('school_id', $schoolId)
                    ->whereRaw('LOWER(role) = ?', ['student'])
                    ->where('firstname', $firstname)
                    ->where('surname', $surname)
                    ->where('third_name', $thirdName)
                    ->exists()) {
                    $rowErrors[] = 'A student with this same full name already exists.';
                }
            }

            $class = $this->resolveOrCreateClass($schoolId, $classes, $row['class'] ?? null);
            $section = $this->resolveOrCreateSection($schoolId, $sections, $row['section'] ?? null);
            $department = $this->resolveOrCreateDepartment($schoolId, $departments, $row['department'] ?? null);

            if (! $class) $rowErrors[] = 'Class was not found. Use class name or ID exactly as saved.';
            if (! $section) $rowErrors[] = 'Section was not found. Use section name or ID exactly as saved.';
            if (! $department) $rowErrors[] = 'Department was not found. Use department name or ID exactly as saved.';

            $readyRows[] = [
                'row' => $rowNumber,
                'firstname' => $firstname,
                'surname' => $surname,
                'third_name' => $thirdName,
                'gender' => $gender,
                'admission_no' => $admissionNo,
                'class_id' => $class?->id,
                'class_name' => $class?->name,
                'section_id' => $section?->id,
                'section_name' => $section?->name,
                'department_id' => $department?->id,
                'department_name' => $department?->name,
                'email' => $email,
                'phone' => trim((string) ($row['phone'] ?? '')),
                'dob' => trim((string) ($row['dob'] ?? '')),
                'address' => trim((string) ($row['address'] ?? '')),
                'blood_group' => trim((string) ($row['blood_group'] ?? '')),
                'religion' => trim((string) ($row['religion'] ?? '')),
                'nationality' => trim((string) ($row['nationality'] ?? '')),
                'status' => empty($rowErrors) ? 'ready' : 'error',
                'errors' => $rowErrors,
                'warnings' => $rowWarnings,
            ];

            foreach ($rowErrors as $error) $errors[] = "Row {$rowNumber}: {$error}";
            foreach ($rowWarnings as $warning) $warnings[] = "Row {$rowNumber}: {$warning}";
        }

        $readyCount = collect($readyRows)->where('status', 'ready')->count();
        $remainingSlots = $admin->remainingStudentSlots();
        if ($remainingSlots !== null && $readyCount > $remainingSlots) {
            $errors[] = "This file has {$readyCount} valid student(s), but your current plan only allows {$remainingSlots} more active student(s).";
        }

        return [
            'summary' => [
                'total_rows' => count($mappedRows),
                'ready_rows' => $readyCount,
                'errors_count' => count($errors),
                'warnings_count' => count($warnings),
                'can_import' => count($errors) === 0 && $readyCount > 0,
            ],
            'rows' => $readyRows,
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    public function import(User $admin, UploadedFile $file): array
    {
        $preview = $this->preview($admin, $file);

        if (! ($preview['summary']['can_import'] ?? false)) {
            return [
                'imported' => 0,
                'preview' => $preview,
                'message' => 'Import was not completed because the file still has errors.',
            ];
        }

        $schoolId = (int) $admin->school_id;
        $settings = SchoolSetting::where('id', $schoolId)->first();
        $autoAdmission = (int) ($settings?->auto_admission ?? 0) === 1;
        $created = [];

        DB::transaction(function () use ($preview, $admin, $schoolId, $settings, $autoAdmission, &$created) {
            foreach (collect($preview['rows'])->where('status', 'ready') as $row) {
                try {
                    $admin->assertCanAddStudents();
                } catch (SubscriptionLimitExceededException $e) {
                    throw $e;
                }

                $regNo = $row['admission_no'] ?: $this->generateAdmissionNo($settings);
                $password = Str::random(8);

                $student = User::create([
                    'firstname' => $row['firstname'],
                    'surname' => $row['surname'],
                    'third_name' => $row['third_name'],
                    'email' => $row['email'] ?: null,
                    'username' => $regNo,
                    'reg_no' => $regNo,
                    'dob' => $row['dob'] ?: null,
                    'address' => $row['address'] ?: null,
                    'level_id' => $row['class_id'],
                    'section_id' => $row['section_id'],
                    'department_id' => $row['department_id'],
                    'blood_group' => $row['blood_group'] ?: null,
                    'religion' => $row['religion'] ?: null,
                    'nationality' => $row['nationality'] ?: null,
                    'password' => Hash::make($password),
                    'default_password' => $password,
                    'sex' => $row['gender'],
                    'role' => 'Student',
                    'school_id' => $schoolId,
                    'phone' => $row['phone'] ?: null,
                    'status' => 1,
                    'student_status' => 'active',
                ]);

                try {
                    $student->assignRole('Student');
                } catch (\Throwable) {
                    // Some older installs rely only on the users.role column.
                }

                $created[] = [
                    'id' => $student->id,
                    'name' => trim($student->firstname . ' ' . $student->surname),
                    'reg_no' => $student->reg_no,
                    'default_password' => $password,
                ];
            }
        });

        return [
            'imported' => count($created),
            'students' => $created,
            'message' => count($created) . ' student(s) imported successfully.',
        ];
    }

    private function readRows(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        if (! in_array($extension, ['xlsx', 'xls', 'csv'], true)) {
            throw new \InvalidArgumentException('Student upload file must be xlsx, xls, or csv.');
        }

        $import = new RawSheetImport();
        $sheets = Excel::toArray($import, $file);
        return $sheets[0] ?? [];
    }

    private function mapRows(array $rows): array
    {
        if (count($rows) < 2) {
            return [];
        }

        $headers = array_map(fn ($value) => $this->normalizeHeader($value), $rows[0]);
        $mapped = [];

        foreach (array_slice($rows, 1) as $row) {
            if (collect($row)->filter(fn ($value) => trim((string) $value) !== '')->isEmpty()) {
                continue;
            }

            $item = [];
            foreach ($headers as $index => $header) {
                if ($header !== '') {
                    $item[$header] = $row[$index] ?? null;
                }
            }
            $mapped[] = $item;
        }

        return $mapped;
    }

    public static function provisionDefaultAcademicStructure(int $schoolId): void
    {
        if ($schoolId <= 0) return;

        $juniorSection = Section::firstOrCreate(
            ['school_id' => $schoolId, 'name' => 'Junior']
        );
        $seniorSection = Section::firstOrCreate(
            ['school_id' => $schoolId, 'name' => 'Senior']
        );

        Department::firstOrCreate(
            ['school_id' => $schoolId, 'name' => 'General']
        );
        Department::firstOrCreate(
            ['school_id' => $schoolId, 'name' => 'Science']
        );
        Department::firstOrCreate(
            ['school_id' => $schoolId, 'name' => 'Arts']
        );
        Department::firstOrCreate(
            ['school_id' => $schoolId, 'name' => 'Commercial']
        );

        $defaultClasses = [
            ['name' => 'JSS 1', 'section_id' => $juniorSection->id],
            ['name' => 'JSS 2', 'section_id' => $juniorSection->id],
            ['name' => 'JSS 3', 'section_id' => $juniorSection->id],
            ['name' => 'SSS 1', 'section_id' => $seniorSection->id],
            ['name' => 'SSS 2', 'section_id' => $seniorSection->id],
            ['name' => 'SSS 3', 'section_id' => $seniorSection->id],
        ];

        foreach ($defaultClasses as $cls) {
            StudentClass::firstOrCreate(
                ['school_id' => $schoolId, 'name' => $cls['name']],
                ['section_id' => $cls['section_id']]
            );
        }
    }

    private function resolveOrCreateClass(int $schoolId, Collection &$classes, $value)
    {
        $val = trim((string) $value);
        if ($val === '') {
            $val = $classes->first()?->name ?? 'JSS 1';
        }

        // 1. Exact ID or name match
        $found = $classes->first(fn ($item) => (string) $item->id === $val)
            ?: $classes->first(fn ($item) => strtolower(trim((string) $item->name)) === strtolower($val));

        if ($found) {
            return $found;
        }

        // 2. Normalized alphanumeric match (e.g. "JSS 1" matches "JSS1", "J.S.S 1" matches "JSS 1")
        $cleanVal = preg_replace('/[^a-z0-9]/', '', strtolower($val));
        if ($cleanVal !== '') {
            $found = $classes->first(fn ($item) => preg_replace('/[^a-z0-9]/', '', strtolower((string) $item->name)) === $cleanVal);
            if ($found) {
                return $found;
            }
        }

        // 3. Auto-create the class for this school so user import succeeds smoothly
        try {
            $created = StudentClass::firstOrCreate(
                ['school_id' => $schoolId, 'name' => $val]
            );
            $classes->push($created);
            return $created;
        } catch (\Throwable) {
            return $classes->first();
        }
    }

    private function resolveOrCreateSection(int $schoolId, Collection &$sections, $value)
    {
        $val = trim((string) $value);
        if ($val === '') {
            $val = $sections->first()?->name ?? 'Junior';
        }

        $found = $sections->first(fn ($item) => (string) $item->id === $val)
            ?: $sections->first(fn ($item) => strtolower(trim((string) $item->name)) === strtolower($val));

        if ($found) {
            return $found;
        }

        $cleanVal = preg_replace('/[^a-z0-9]/', '', strtolower($val));
        if ($cleanVal !== '') {
            $found = $sections->first(fn ($item) => preg_replace('/[^a-z0-9]/', '', strtolower((string) $item->name)) === $cleanVal);
            if ($found) {
                return $found;
            }
        }

        try {
            $created = Section::firstOrCreate(
                ['school_id' => $schoolId, 'name' => $val]
            );
            $sections->push($created);
            return $created;
        } catch (\Throwable) {
            return $sections->first();
        }
    }

    private function resolveOrCreateDepartment(int $schoolId, Collection &$departments, $value)
    {
        $val = trim((string) $value);
        if ($val === '') {
            $val = $departments->first()?->name ?? 'General';
        }

        $found = $departments->first(fn ($item) => (string) $item->id === $val)
            ?: $departments->first(fn ($item) => strtolower(trim((string) $item->name)) === strtolower($val));

        if ($found) {
            return $found;
        }

        $cleanVal = preg_replace('/[^a-z0-9]/', '', strtolower($val));
        if ($cleanVal !== '') {
            $found = $departments->first(fn ($item) => preg_replace('/[^a-z0-9]/', '', strtolower((string) $item->name)) === $cleanVal);
            if ($found) {
                return $found;
            }
        }

        try {
            $created = Department::firstOrCreate(
                ['school_id' => $schoolId, 'name' => $val]
            );
            $departments->push($created);
            return $created;
        } catch (\Throwable) {
            return $departments->first();
        }
    }

    private function normalizeGender($value): string
    {
        $value = strtolower(trim((string) $value));
        return match ($value) {
            'm', 'male' => 'Male',
            'f', 'female' => 'Female',
            default => '',
        };
    }

    private function generateAdmissionNo(?SchoolSetting $settings): string
    {
        $prefix = (string) ($settings?->prefix ?? 'GQ');

        do {
            $regNo = $prefix . random_int(100000, 999999);
        } while (User::where('reg_no', $regNo)->exists());

        return $regNo;
    }

    private function normalizeHeader($value): string
    {
        $value = strtolower(trim((string) $value));
        $value = str_replace(['admission number', 'admission no', 'reg no', 'registration no', 'middle name'], ['admission_no', 'admission_no', 'admission_no', 'admission_no', 'third_name'], $value);
        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?: '';
        return trim($value, '_');
    }
}
