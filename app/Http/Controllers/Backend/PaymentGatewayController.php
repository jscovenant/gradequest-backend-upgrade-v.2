<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\PaymentGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\{StudentFee, Payment};

class PaymentGatewayController extends Controller
{
   
 // Load School Gateway + Bank Info
    public function show()
    {
        $schoolId = Auth::user()->school_id;

        $gateway = PaymentGateway::where('school_id', $schoolId)->first();

        return response()->json($gateway);
    }

    // Save or Update Gateway + Bank Info
    public function store(Request $request)
    {
        $schoolId = Auth::user()->school_id;

        $validated = $request->validate([
            'payment_url' => 'nullable|string',
            'bank_name' => 'nullable|string|max:255',
            'account_number' => 'nullable|string|max:50',
            'account_name' => 'nullable|string|max:255',
            'is_active' => 'boolean',
        ]);

        $gateway = PaymentGateway::updateOrCreate(
            ['school_id' => $schoolId],
            $validated
        );

        return response()->json([
            'message' => 'Payment gateway settings updated successfully.',
            'data' => $gateway
        ]);
    }
    
    
    public function getGatewayForSchool($school_id)
{
    // Find active gateway
    $gateway = PaymentGateway::where('school_id', $school_id)
        ->where('is_active', 1)
        ->first();

    if (!$gateway) {
        return response()->json([
            'message' => 'Payment gateway not configured for this school'
        ], 404);
    }

    return response()->json([
        'gateway_name' => $gateway->gateway_name,
        'public_key' => $gateway->public_key,
        'payment_url' => $gateway->payment_url, 
        'account_details' => $gateway->account_details, 
        'is_active' => $gateway->is_active,
    ]);
}


}
