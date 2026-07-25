<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionPlanFeature;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SubscriptionPlanController extends Controller
{
    public function index()
    {
        $query = SubscriptionPlan::query()->orderBy('id', 'desc');

        if (Schema::hasTable('subscription_plan_features')) {
            $query->with('features');
        }

        return $query->get();
    }

        /**
     * 🔹 calcel button
     */



public function store(Request $request)
{
    $data = $request->validate([
        'name' => 'required|string|max:255',
        'price' => 'nullable|numeric|min:0',
        'price_per_student' => 'required|numeric|min:0',
        'paystack_plan_code' => 'nullable|string',
        'currency' => 'required|string|max:10',
        'duration_in_days' => 'required|integer|min:0',
        'billing_interval' => 'nullable|string|max:30',
        'max_teachers' => 'nullable|integer|min:0',
        'max_students' => 'required|integer|min:0',
        'description' => 'nullable|string',
        'is_active' => 'boolean',
        'features' => 'array', // array of features
    ]);

    $features = $data['features'] ?? [];
    $data['price'] = $data['price'] ?? $data['price_per_student'];
    $data['billing_interval'] = $data['billing_interval'] ?? 'term';
    $data['features'] = $features;

    $plan = DB::transaction(function () use ($data, $features) {
        $plan = SubscriptionPlan::create($data);
        $this->syncFeatures($plan, $features);

        return $plan->fresh('features');
    });

    return response()->json([
        'message' => 'Plan created successfully',
        'plan' => $plan,
    ]);
}




public function update(Request $request, $id)
{
    $plan = SubscriptionPlan::findOrFail($id);

    $data = $request->validate([
        'name' => 'required|string|max:255',
        'price' => 'nullable|numeric|min:0',
        'price_per_student' => 'required|numeric|min:0',
        'paystack_plan_code' => 'nullable|string',
        'currency' => 'required|string|max:10',
        'duration_in_days' => 'required|integer|min:0',
        'billing_interval' => 'nullable|string|max:30',
        'max_teachers' => 'nullable|integer|min:0',
        'max_students' => 'required|integer|min:0',
        'description' => 'nullable|string',
        'is_active' => 'boolean',
        'features' => 'array',
    ]);

    $features = $data['features'] ?? [];
    $data['price'] = $data['price'] ?? $data['price_per_student'];
    $data['billing_interval'] = $data['billing_interval'] ?? 'term';
    $data['features'] = $features;

    $plan = DB::transaction(function () use ($plan, $data, $features) {
        $plan->update($data);
        $this->syncFeatures($plan, $features);

        return $plan->fresh('features');
    });

    return response()->json([
        'message' => 'Plan updated successfully',
        'plan' => $plan,
    ]);
}




   public function destroy($id)
{
    $plan = SubscriptionPlan::findOrFail($id);

    // Prevent deletion of FREE plan
    if ($plan->is_free || $plan->price == 0) {
        return response()->json([
            'message' => 'The free plan cannot be deleted.'
        ], 403);
    }

    $plan->delete();

    return response()->json(['message' => 'Plan deleted successfully']);
}

private function syncFeatures(SubscriptionPlan $plan, array $features): void
{
    if (! Schema::hasTable('subscription_plan_features')) {
        return;
    }

    $keys = [];

    foreach ($features as $feature) {
        if (is_string($feature)) {
            $feature = [
                'feature_key' => $feature,
                'feature_name' => ucwords(str_replace('_', ' ', $feature)),
                'is_enabled' => true,
            ];
        }

        $key = trim((string) ($feature['feature_key'] ?? ''));
        if ($key === '') {
            continue;
        }

        $keys[] = $key;

        SubscriptionPlanFeature::updateOrCreate(
            [
                'subscription_plan_id' => $plan->id,
                'feature_key' => $key,
            ],
            [
                'feature_name' => $feature['feature_name'] ?? ucwords(str_replace('_', ' ', $key)),
                'is_enabled' => (bool) ($feature['is_enabled'] ?? true),
                'limit_type' => $feature['limit_type'] ?? null,
                'limit_count' => (int) ($feature['limit_count'] ?? 0),
            ]
        );
    }

    SubscriptionPlanFeature::where('subscription_plan_id', $plan->id)
        ->when($keys !== [], fn ($query) => $query->whereNotIn('feature_key', $keys))
        ->when($keys === [], fn ($query) => $query)
        ->delete();
}


}
