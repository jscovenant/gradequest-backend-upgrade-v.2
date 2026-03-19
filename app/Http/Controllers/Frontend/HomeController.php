<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\SubscriptionPlan;
use Illuminate\Http\JsonResponse;

class HomeController extends Controller
{
   public function subscriptionPlans(): JsonResponse
    {
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
                            return [
                                'text' => $item['text']
                                    ?? $item['feature_name']
                                    ?? $item['name']
                                    ?? '',
                                'note' => $item['note']
                                    ?? $item['feature_key']
                                    ?? null,
                            ];
                        }

                        return null;
                    })
                    ->filter(fn ($item) => !empty($item['text']))
                    ->values();

                $currency = $plan->currency ?: 'NGN';

                return [
                    'id' => (string) $plan->id,
                    'name' => $plan->name,
                    'price' => $this->formatMoney((float) $plan->price, $currency),
                    'raw_price' => (float) $plan->price,
                    'period' => $this->resolvePeriod((int) $plan->duration_in_days, (float) $plan->price),
                    'tagline' => $plan->description ?: 'Flexible plan for your school.',
                    'popular' => $index === 1,
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
            $days === 90 => '/ 3 months',
            $days === 7 => '/ week',
            default => "/ {$days} days",
        };
    }
}
