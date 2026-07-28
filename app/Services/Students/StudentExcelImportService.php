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

            $class = $this->resolveSetup($classes, $row['class'] ?? null);
            $section = $this->resolveSetup($sections, $row['section'] ?? null);
            $department = $this->resolveSetup($departments, $row['department'] ?? null);

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

    private function resolveSetup(Collection $items, $value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        return $items->first(fn ($item) => (string) $item->id === $value)
            ?: $items->first(fn ($item) => strtolower((string) $item->name) === strtolower($value));
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
