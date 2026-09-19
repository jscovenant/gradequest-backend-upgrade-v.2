<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SchoolWebsiteSetting extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'core_values' => 'array',
        'facilities' => 'array',
        'programs' => 'array',
        'gallery' => 'array',
        'testimonials' => 'array',
        'faqs' => 'array',
        'custom_pages' => 'array',
        'nav_links' => 'array',
        'announcements' => 'array',
        'show_admissions_cta' => 'boolean',
        'show_fee_payment_cta' => 'boolean',
        'show_result_checker_cta' => 'boolean',
        'show_portal_login_cta' => 'boolean',
        'is_published' => 'boolean',
    ];

    public function school()
    {
        return $this->belongsTo(SchoolSetting::class, 'school_id');
    }

    /**
     * Default professional website configuration template.
     */
    public static function defaultSettings(SchoolSetting $school): array
    {
        $schoolName = $school->school_name ?: 'Our Esteemed Academy';
        return [
            'school_id' => $school->id,
            'theme_color_primary' => '#0F2744',
            'theme_color_secondary' => '#D97706',
            'theme_color_accent' => '#2563EB',
            'theme_color_text' => '#1E293B',
            'theme_color_background' => '#FFFFFF',
            'font_family' => 'Plus Jakarta Sans',
            'site_title' => $schoolName,
            'tagline' => 'Knowledge, Character & Leadership',
            'hero_badge' => 'Admissions Open for 2026/2027 Academic Session',
            'hero_title' => "Empowering Future Leaders at {$schoolName}",
            'hero_subtitle' => 'Providing world-class holistic education, high moral standards, and advanced digital learning for tomorrow’s leaders.',
            'hero_cta_text' => 'Apply for Admission',
            'hero_cta_url' => '#admission',
            'hero_secondary_cta_text' => 'School Portal Login',
            'hero_secondary_cta_url' => '/login',
            'principal_name' => 'The Principal',
            'principal_title' => 'Head of School',
            'principal_welcome_title' => "Welcome to {$schoolName}",
            'principal_welcome_message' => "At {$schoolName}, we are dedicated to academic excellence, sound moral character, and innovative skills that prepare our students to excel globally. We welcome every child into a vibrant community where dreams become achievements.",
            'about_title' => 'Why Choose Our School',
            'about_content' => "With a proven track record of academic distinction, dedicated educators, and state-of-the-art facilities, {$schoolName} provides a nurturing environment where every child's unique potential is unlocked and celebrated.",
            'motto' => 'Excellence in Knowledge and Character',
            'mission' => 'To deliver high-impact education through modern pedagogies, ethical values, and digital technology that empowers every student.',
            'vision' => 'To be a premier learning institution raising globally competitive leaders of integrity and competence.',
            'core_values' => ['Academic Excellence', 'Moral Integrity', 'Innovation & STEM', 'Discipline & Leadership', 'Creativity'],
            'facilities' => [
                [
                    'title' => 'Modern Science Laboratories',
                    'desc' => 'Fully equipped Physics, Chemistry, and Biology labs enabling deep hands-on discovery and practical mastery.',
                    'icon' => 'FlaskConical',
                ],
                [
                    'title' => 'Ultra-Modern ICT & CBT Center',
                    'desc' => 'High-speed networked computer workstations for coding, digital literacy, and online CBT examinations.',
                    'icon' => 'Monitor',
                ],
                [
                    'title' => 'Standard Library & Research Hub',
                    'desc' => 'Extensive collection of physical and digital volumes supporting continuous inquiry and study.',
                    'icon' => 'BookOpen',
                ],
                [
                    'title' => 'Sports & Recreation Complex',
                    'desc' => 'Spacious sports grounds, football pitch, athletics track, basketball court, and indoor games facilities.',
                    'icon' => 'Trophy',
                ],
            ],
            'programs' => [
                [
                    'name' => 'Early Years & Nursery',
                    'age_range' => 'Ages 2 – 5',
                    'desc' => 'Montessori-inspired foundation building sensory awareness, phonics, numeracy, and social collaboration.',
                    'badge' => 'Early Learning',
                ],
                [
                    'name' => 'Primary School',
                    'age_range' => 'Ages 6 – 11',
                    'desc' => 'Rigorous standard curriculum fostering core literacy, mathematics, STEM discovery, languages, and moral ethics.',
                    'badge' => 'Grade 1 - 6',
                ],
                [
                    'name' => 'Junior Secondary (JSS 1 - 3)',
                    'age_range' => 'Ages 11 – 14',
                    'desc' => 'Comprehensive foundation in sciences, humanities, pre-vocational skills, and preparatory BECE / NECO excellence.',
                    'badge' => 'Lower Secondary',
                ],
                [
                    'name' => 'Senior Secondary (SSS 1 - 3)',
                    'age_range' => 'Ages 14 – 17',
                    'desc' => 'Specialized Science, Arts, and Commercial streams preparing students for outstanding WAEC, NECO, and JAMB success.',
                    'badge' => 'Upper Secondary',
                ],
            ],
            'gallery' => [],
            'testimonials' => [
                [
                    'name' => 'Dr. A. Adebayo',
                    'role' => 'Parent (JSS 2 & SS 1)',
                    'content' => "The transformation in my children's confidence and academic performance within just one session has been phenomenal.",
                    'rating' => 5,
                ],
                [
                    'name' => 'Mrs. N. Okonkwo',
                    'role' => 'Parent (Primary 4)',
                    'content' => 'The teachers are exceptionally caring and the digital portal makes checking results and paying school fees completely stress-free.',
                    'rating' => 5,
                ],
            ],
            'faqs' => [
                [
                    'question' => 'How can I apply for admission?',
                    'answer' => 'You can complete our native online admission form directly on this website. Simply click "Apply for Admission", fill the student details, and complete the application fee online for instant confirmation.',
                ],
                [
                    'question' => 'What curriculum does the school follow?',
                    'answer' => 'We offer a rich blended curriculum combining the Nigerian National Basic & Secondary Education Curriculum with international British curriculum standards.',
                ],
                [
                    'question' => 'How do parents monitor academic performance?',
                    'answer' => 'Parents receive direct secure login credentials to the Parent Portal to track real-time attendance, daily performance, continuous assessment scores, and verified term broadsheet report cards.',
                ],
            ],
            'custom_pages' => [],
            'nav_links' => [
                ['label' => 'Home', 'href' => '#home', 'is_enabled' => true, 'is_button' => false],
                ['label' => 'About Us', 'href' => '#about', 'is_enabled' => true, 'is_button' => false],
                ['label' => 'Academics', 'href' => '#programs', 'is_enabled' => true, 'is_button' => false],
                ['label' => 'Facilities', 'href' => '#facilities', 'is_enabled' => true, 'is_button' => false],
                ['label' => 'Admissions', 'href' => '#admission', 'is_enabled' => true, 'is_button' => false],
                ['label' => 'Contact', 'href' => '#contact', 'is_enabled' => true, 'is_button' => false],
            ],
            'announcements' => [],
            'contact_email' => $school->email ?: 'info@school.edu.ng',
            'contact_phone' => $school->phone ?: $school->phone_number ?: '+234 800 000 0000',
            'contact_address' => $school->address ?: 'Campus Location, Nigeria',
            'show_admissions_cta' => true,
            'show_fee_payment_cta' => true,
            'show_result_checker_cta' => true,
            'show_portal_login_cta' => true,
            'is_published' => true,
        ];
    }
}
