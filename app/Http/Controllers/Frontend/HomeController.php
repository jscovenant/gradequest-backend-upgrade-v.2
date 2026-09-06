<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Models\GradiosEduBillingPolicy;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class HomeController extends Controller
{
    public function subscriptionPlans(): JsonResponse
    {
        $policy = GradiosEduBillingPolicy::first();
        $whatsappNumber = $policy?->support_whatsapp;
        if (!$whatsappNumber) {
            $admin = User::whereIn('role', ['SuperAdmin', 'superadmin', 'Owner', 'Admin'])
                ->whereNotNull('phone')
                ->where('phone', '!=', '')
                ->first();
            $whatsappNumber = $admin?->phone ?: '08165748374';
        }

        $cleanWhatsapp = preg_replace('/[^0-9]/', '', (string) $whatsappNumber);
        if (str_starts_with($cleanWhatsapp, '0')) {
            $cleanWhatsapp = '234' . substr($cleanWhatsapp, 1);
        }

        $platformFee = (float) ($policy?->platform_fee_per_student ?: 1000);

        $plans = SubscriptionPlan::query()
            ->where('is_active', 1)
            ->orderBy('price')
            ->get()
            ->map(function (SubscriptionPlan $plan, int $index) {
                $features = $plan->features;

                if (is_string($features)) {
                    $decoded = json_decode($features, true);
                    $features = json_last_error() === JSON_ERROR_NONE ? $decoded : [];
                }

                if (!is_array($features)) {
                    $features = [];
                }

                $featureItems = collect($features)
                    ->map(function ($item) {
                        if (is_string($item)) {
                            return [
                                'text' => $item,
                                'note' => null,
                            ];
                        }

                        if (is_array($item)) {
                            $text = $item['text']
                                ?? $item['feature_name']
                                ?? $item['name']
                                ?? '';
                            $enabled = $item['is_enabled'] ?? true;
                            if (!$enabled && $enabled !== '1' && $enabled !== 1) {
                                return null;
                            }
                            return [
                                'text' => $text,
                                'note' => $item['note']
                                    ?? $item['feature_key']
                                    ?? null,
                            ];
                        }

                        return null;
                    })
                    ->filter(fn ($item) => !empty($item['text']))
                    ->unique('text')
                    ->values();

                $currency = $plan->currency ?: 'NGN';

                return [
                    'id' => (string) $plan->id,
                    'name' => $plan->name,
                    'price' => $this->formatMoney((float) $plan->price, $currency),
                    'raw_price' => (float) $plan->price,
                    'period' => $this->resolvePeriod((int) $plan->duration_in_days, (float) $plan->price),
                    'tagline' => $plan->description ?: 'Flexible plan for your school.',
                    'popular' => $index === 1 || stripos($plan->name, 'growth') !== false || stripos($plan->name, 'plus') !== false,
                    'cta' => (float) $plan->price > 0 ? 'Get Started' : 'Start Free',
                    'paystack_plan_code' => $plan->paystack_plan_code,
                    'max_teachers' => $plan->max_teachers,
                    'max_students' => $plan->max_students,
                    'duration_in_days' => (int) $plan->duration_in_days,
                    'currency' => $currency,
                    'features' => $featureItems,
                ];
            })
            ->values();

        return response()->json([
            'data' => $plans,
            'platform' => [
                'support_whatsapp' => $cleanWhatsapp ?: '2348165748374',
                'support_whatsapp_raw' => (string) ($whatsappNumber ?: '08165748374'),
                'support_email' => 'gradequestapp@gmail.com',
                'platform_fee_per_student' => $platformFee,
                'formatted_platform_fee' => '₦' . number_format($platformFee, 0),
                'sales_partner_term_1_commission' => (float) ($policy?->sales_partner_term_1_commission_rate ?? 30.00),
                'sales_partner_retention_commission' => (float) ($policy?->sales_partner_retention_commission_rate ?? 12.00),
                'promo' => $policy?->promo_enabled ? [
                    'title' => $policy->promo_title,
                    'description' => $policy->promo_description,
                    'bonus_days' => $policy->promo_bonus_days,
                    'min_students' => $policy->promo_min_students,
                ] : null,
            ],
        ]);
    }

    public function platformInfo(): JsonResponse
    {
        $policy = GradiosEduBillingPolicy::first();
        $whatsappNumber = $policy?->support_whatsapp;
        if (!$whatsappNumber) {
            $admin = User::whereIn('role', ['SuperAdmin', 'superadmin', 'Owner', 'Admin'])
                ->whereNotNull('phone')
                ->where('phone', '!=', '')
                ->first();
            $whatsappNumber = $admin?->phone ?: '08165748374';
        }

        $cleanWhatsapp = preg_replace('/[^0-9]/', '', (string) $whatsappNumber);
        if (str_starts_with($cleanWhatsapp, '0')) {
            $cleanWhatsapp = '234' . substr($cleanWhatsapp, 1);
        }

        $platformFee = (float) ($policy?->platform_fee_per_student ?: 1000);

        return response()->json([
            'status' => 'success',
            'data' => [
                'support_whatsapp' => $cleanWhatsapp ?: '2348165748374',
                'support_whatsapp_raw' => (string) ($whatsappNumber ?: '08165748374'),
                'support_email' => 'gradequestapp@gmail.com',
                'platform_fee_per_student' => $platformFee,
                'formatted_platform_fee' => '₦' . number_format($platformFee, 0),
                'sales_partner_term_1_commission' => (float) ($policy?->sales_partner_term_1_commission_rate ?? 30.00),
                'sales_partner_retention_commission' => (float) ($policy?->sales_partner_retention_commission_rate ?? 12.00),
                'promo' => $policy?->promo_enabled ? [
                    'title' => $policy->promo_title,
                    'description' => $policy->promo_description,
                    'bonus_days' => $policy->promo_bonus_days,
                    'min_students' => $policy->promo_min_students,
                ] : null,
            ],
        ]);
    }

    private function formatMoney(float $amount, string $currency): string
    {
        if ($amount <= 0) {
            return 'Free';
        }

        $symbol = match (strtoupper($currency)) {
            'NGN' => '₦',
            'USD' => '$',
            'GBP' => '£',
            'EUR' => '€',
            default => strtoupper($currency) . ' ',
        };

        return $symbol . number_format($amount, 0);
    }

    private function resolvePeriod(int $days, float $price): string
    {
        if ($price <= 0) {
            return '/ trial';
        }

        return match (true) {
            $days >= 365 => '/ year',
            $days >= 28 && $days <= 31 => '/ month',
            $days === 90 => '/ term (90 days)',
            $days === 7 => '/ week',
            default => "/ {$days} days",
        };
    }

    public function activeSchools(): JsonResponse
    {
        $schools = \App\Models\SchoolSetting::query()
            ->whereNotNull('school_name')
            ->where('school_name', '!=', '')
            ->where('school_name', 'not like', '%test%')
            ->where('school_name', 'not like', '%demo%')
            ->get()
            ->map(function ($s) {
                $location = 'Nigeria';
                if (!empty($s->state)) {
                    $location = $s->state;
                } elseif (!empty($s->city)) {
                    $location = $s->city;
                } elseif (!empty($s->address)) {
                    if (preg_match('/(Lagos|Ogun|Ondo|Kaduna|Anambra|Niger|Enugu|Oyo|Abuja|Rivers|Imo|Delta|Kano|Edo|Abia|Osun|Ekiti|Kwara|Plateau|Benue)/i', $s->address, $m)) {
                        $location = ucfirst(strtolower($m[1]));
                    }
                }

                $tag = 'Basic & Secondary';
                if (stripos($s->school_name, 'college') !== false) {
                    $tag = 'Comprehensive College';
                } elseif (stripos($s->school_name, 'primary') !== false) {
                    $tag = 'Nursery & Primary';
                } elseif (stripos($s->school_name, 'group') !== false) {
                    $tag = 'Nursery, Primary & College';
                } elseif (stripos($s->school_name, 'institute') !== false || stripos($s->school_name, 'technology') !== false) {
                    $tag = 'Higher Institute & College';
                } elseif (stripos($s->school_name, 'academy') !== false) {
                    $tag = 'Model Academy';
                }

                return [
                    'id' => $s->id,
                    'name' => trim($s->school_name),
                    'tag' => $tag,
                    'location' => $location,
                ];
            })
            ->unique('name')
            ->values();

        return response()->json([
            'status' => true,
            'data' => $schools,
            'total' => $schools->count(),
        ]);
    }
}
