<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AiSalesAgentConfig extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'temperature' => 'float',
        'inactivity_threshold_days' => 'integer',
        'auto_scan_enabled' => 'boolean',
        'whatsapp_outreach_enabled' => 'boolean',
        'max_daily_outreach' => 'integer',
    ];

    public static function getActiveConfig(): self
    {
        return self::firstOrCreate(
            ['id' => 1],
            [
                'openai_api_key' => env('OPENAI_API_KEY'),
                'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
                'temperature' => 0.70,
                'agent_name' => 'Sarah - SchoolProfit Growth Consultant',
                'inactivity_threshold_days' => 14,
                'auto_scan_enabled' => true,
                'whatsapp_outreach_enabled' => true,
                'max_daily_outreach' => 50,
                'support_whatsapp_number' => '+2348000000000',
                'demo_booking_url' => 'https://schoolprofit.ng/book-demo',
                'signup_url' => 'https://schoolprofit.ng/onboarding',
                'pricing_url' => 'https://schoolprofit.ng/school-plans',
            ]
        );
    }
}
