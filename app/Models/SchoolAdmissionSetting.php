<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SchoolAdmissionSetting extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'is_open' => 'boolean',
        'require_payment' => 'boolean',
        'auto_admit' => 'boolean',
        'application_fee' => 'decimal:2',
        'platform_fee' => 'decimal:2',
        'available_classes' => 'array',
        'requirements' => 'array',
        'start_date' => 'date',
        'closing_date' => 'date',
    ];

    public function school()
    {
        return $this->belongsTo(SchoolSetting::class, 'school_id');
    }

    public static function defaultSettings(SchoolSetting $school): array
    {
        return [
            'school_id' => $school->id,
            'is_open' => true,
            'admission_session_name' => date('Y') . '/' . (date('Y') + 1) . ' Academic Session',
            'application_fee' => 5000.00,
            'platform_fee' => 1000.00,
            'require_payment' => true,
            'available_classes' => ['Creche', 'Nursery 1', 'Nursery 2', 'Primary 1', 'Primary 2', 'Primary 3', 'Primary 4', 'Primary 5', 'JSS 1', 'JSS 2', 'SS 1 Science', 'SS 1 Arts', 'SS 1 Commercial'],
            'instructions' => 'Please provide accurate information for the candidate and parent/guardian. Upon submission, you will be guided to complete the application fee to generate your official entrance slip.',
            'requirements' => [
                'Recent passport photograph (white background)',
                'Copy of candidate birth certificate / Age declaration',
                'Previous school report card / testimonial (for transfer applicants)',
                'Parent/Guardian valid phone number and residential address',
            ],
            'contact_email' => $school->email ?: 'admissions@school.edu.ng',
            'contact_phone' => $school->phone ?: '+234 800 000 0000',
            'auto_admit' => false,
        ];
    }
}
