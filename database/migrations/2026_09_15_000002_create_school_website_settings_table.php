<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('school_website_settings')) {
            Schema::create('school_website_settings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->unique()->constrained('school_settings')->cascadeOnDelete();
                
                // Professional Brand Colors & Typography
                $table->string('theme_color_primary', 20)->default('#0F2744');     // Deep Navy default
                $table->string('theme_color_secondary', 20)->default('#D97706');   // Royal Gold default
                $table->string('theme_color_accent', 20)->default('#2563EB');      // Bright Blue default
                $table->string('theme_color_text', 20)->default('#1E293B');        // Slate Dark text default
                $table->string('theme_color_background', 20)->default('#FFFFFF');  // Clean White default
                $table->string('font_family', 50)->default('Plus Jakarta Sans');

                // Header & Hero Section
                $table->string('site_title')->nullable();
                $table->string('tagline')->nullable();
                $table->string('hero_badge')->nullable();
                $table->string('hero_title')->nullable();
                $table->text('hero_subtitle')->nullable();
                $table->string('hero_image')->nullable();
                $table->string('hero_cta_text')->default('Apply for Admission');
                $table->string('hero_cta_url')->default('#admission');
                $table->string('hero_secondary_cta_text')->default('Explore Portal');
                $table->string('hero_secondary_cta_url')->default('/login');

                // Principal / Proprietor Section
                $table->string('principal_name')->nullable();
                $table->string('principal_title')->nullable()->default('Principal & Head of School');
                $table->string('principal_photo')->nullable();
                $table->string('principal_welcome_title')->nullable()->default('Welcome to Our School');
                $table->longText('principal_welcome_message')->nullable();

                // About Us & Philosophy
                $table->string('about_title')->nullable()->default('Nurturing Leaders of Tomorrow');
                $table->longText('about_content')->nullable();
                $table->string('about_image')->nullable();
                $table->text('motto')->nullable();
                $table->text('mission')->nullable();
                $table->text('vision')->nullable();
                $table->json('core_values')->nullable();

                // Dynamic Sections (JSON structures)
                $table->json('facilities')->nullable();       // [{ title, desc, icon, image }]
                $table->json('programs')->nullable();         // [{ name, age_range, desc, badge }]
                $table->json('gallery')->nullable();          // [{ image_url, title, category }]
                $table->json('testimonials')->nullable();     // [{ name, role, content, avatar, rating }]
                $table->json('faqs')->nullable();             // [{ question, answer }]
                $table->json('custom_pages')->nullable();     // [{ slug, title, content, is_published, show_in_menu }]
                $table->json('nav_links')->nullable();        // [{ label, href, is_enabled, is_button, target }]
                $table->json('announcements')->nullable();    // [{ title, date, content, badge }]

                // Contact & Location
                $table->string('contact_email')->nullable();
                $table->string('contact_phone')->nullable();
                $table->text('contact_address')->nullable();
                $table->text('google_map_embed_url')->nullable();
                $table->string('facebook_url')->nullable();
                $table->string('instagram_url')->nullable();
                $table->string('twitter_url')->nullable();
                $table->string('linkedin_url')->nullable();
                $table->string('youtube_url')->nullable();

                // Control Switches
                $table->boolean('show_admissions_cta')->default(true);
                $table->boolean('show_fee_payment_cta')->default(true);
                $table->boolean('show_result_checker_cta')->default(true);
                $table->boolean('show_portal_login_cta')->default(true);
                $table->boolean('is_published')->default(true);
                
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('school_website_settings');
    }
};
