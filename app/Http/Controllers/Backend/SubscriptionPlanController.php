<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionPlan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
class SubscriptionPlanController extends Controller
{
 public function index()
    {
        return SubscriptionPlan::orderBy('id', 'desc')->get();
    }

public function store(Request $request)
{
    $data = $request->validate([
        'name' => 'required|string|max:255',
        'price' => 'required|numeric|min:0',
        'paystack_plan_code' => 'nullable|string',
        'currency' => 'required|string|max:10',
        'duration_in_days' => 'required|integer|min:0',
        'max_teachers' => 'nullable|integer|min:0',
        'max_students' => 'nullable|integer|min:0',
        'description' => 'nullable|string',
        'is_active' => 'boolean',
        'features' => 'array', // array of features
    ]);

    // Save features as JSON
    $data['features'] = json_encode($data['features'] ?? []);

    $plan = SubscriptionPlan::create($data);

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
        'price' => 'required|numeric|min:0',
         'paystack_plan_code' => 'nullable|string',
        'currency' => 'required|string|max:10',
        'duration_in_days' => 'required|integer|min:0',
        'max_teachers' => 'nullable|integer|min:0',
        'max_students' => 'nullable|integer|min:0',
        'description' => 'nullable|string',
        'is_active' => 'boolean',
        'features' => 'array',
    ]);

    $data['features'] = json_encode($data['features'] ?? []);

    $plan->update($data);

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


}
