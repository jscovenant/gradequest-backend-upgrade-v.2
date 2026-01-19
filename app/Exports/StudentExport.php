<?php

namespace App\Exports;

use App\Models\User;
use App\Models\TeacherEnrollment;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\FromCollection;

class StudentExport implements FromCollection,  WithHeadings, WithMapping
{
    /**
     * @return \Illuminate\Support\Collection
     */
    public function collection()
    {
        // $user = Auth::user();
        // return User::where('role', 'student')
        //     ->where('school_id', $user->school_id)->get();

        $user = Auth::user();
        if ($user->role == 'Teacher') {

            $enrolledLevels = TeacherEnrollment::where('user_id', $user->id)
                ->where('enroll', '1')
                ->pluck('level_id');

            // Get all students in the enrolled levels
            $stu = User::where('role', 'student')
                ->where('school_id', $user->school_id)
                ->whereHas('level', function ($query) use ($enrolledLevels) {
                    $query->whereIn('id', $enrolledLevels);
                })
                ->latest()
                ->paginate(8);

            return $stu;
        } elseif ($user->role == 'Admin') {


            $stu =  User::where('role', 'student')
                ->where('school_id', $user->school_id)
                ->latest()->paginate(8);


            return $stu;
        }
    }



    public function map($user): array
    {

        return [
            $user->reg_no,
            $user->surname,
            $user->firstname,
            $user->email,
            $user->phone,
            $user->address,
            $user->sex,
            $user->dob
        ];
    }

    public function headings(): array
    {
        return [
            'Reg No',
            'Surname',
            'First Name',
            'Email',
            'Phone',
            'Address',
            'Sex',
            'DOB'
        ];
    }
}
