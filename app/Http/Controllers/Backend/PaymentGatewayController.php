<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\PaymentGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PaymentGatewayController extends Controller
{
    /**
     * Load all gateways for the school + default gateway
     */
    public function show()
    {
        $schoolId = Auth::user()->school_id;

        $gateways = PaymentGateway::where('school_id', $schoolId)
            ->orderByDesc('is_default')
            ->orderByDesc('is_active')
            ->get();

        $default = $gateways->firstWhere('is_default', 1);

        return response()->json([
            'default' => $default,
            'gateways' => $gateways,
        ]);
    }

    /**
     * Create or Update a gateway config (per provider+mode)
     * Supports multiple gateways per school.
     */
    public function store(Request $request)
    {
        $schoolId = Auth::user()->school_id;

        $validated = $request->validate([
            'provider' => 'required|string|max:50',     // paystack, flutterwave, stripe, manual_bank
            'mode' => 'required|in:test,live',

            // Gateway credentials (never return secret_key to FE if you can avoid it)
            'public_key' => 'nullable|string|max:255',
            'secret_key' => 'nullable|string|max:255',
            'webhook_secret' => 'nullable|string|max:255',
            'merchant_email' => 'nullable|email|max:255',

            // Capabilities/config
            'channels' => 'nullable|array',
            'currency' => 'nullable|string|max:10',
            'country' => 'nullable|string|max:2',

            // Manual bank fields (still supported)
            'bank_name' => 'nullable|string|max:255',
            'account_number' => 'nullable|string|max:50',
            'account_name' => 'nullable|string|max:255',

            // Old payment_url still optional if you want callback/payment link
            'payment_url' => 'nullable|string|max:255',

            'config' => 'nullable|array',

            'is_active' => 'nullable|boolean',
            'is_default' => 'nullable|boolean',
        ]);

        // Normalize
        $provider = strtolower(trim($validated['provider']));
        $mode = $validated['mode'];

        // Ensure only one default gateway per school
        if (!empty($validated['is_default'])) {
            PaymentGateway::where('school_id', $schoolId)->update(['is_default' => 0]);
        }

        $gateway = PaymentGateway::updateOrCreate(
            [
                'school_id' => $schoolId,
                'provider' => $provider,
                'mode' => $mode,
            ],
            [
                'public_key' => $validated['public_key'] ?? null,
                'secret_key' => $validated['secret_key'] ?? null,           // Consider encrypting
                'webhook_secret' => $validated['webhook_secret'] ?? null,   // Consider encrypting
                'merchant_email' => $validated['merchant_email'] ?? null,

                'channels' => $validated['channels'] ?? null,
                'currency' => $validated['currency'] ?? 'NGN',
                'country' => $validated['country'] ?? 'NG',

                // manual bank (optional)
                'bank_name' => $validated['bank_name'] ?? null,
                'account_number' => $validated['account_number'] ?? null,
                'account_name' => $validated['account_name'] ?? null,

                'payment_url' => $validated['payment_url'] ?? null,

                'config' => $validated['config'] ?? null,

                'is_active' => array_key_exists('is_active', $validated) ? (int)$validated['is_active'] : 1,
                'is_default' => !empty($validated['is_default']) ? 1 : 0,
            ]
        );

        return response()->json([
            'message' => 'Payment gateway settings saved successfully.',
            'data' => $gateway,
        ]);
    }

    /**
     * Set a gateway as default for the school
     */
    public function setDefault($id)
    {
        $schoolId = Auth::user()->school_id;

        $gateway = PaymentGateway::where('school_id', $schoolId)->where('id', $id)->firstOrFail();

        PaymentGateway::where('school_id', $schoolId)->update(['is_default' => 0]);

        $gateway->is_default = 1;
        $gateway->is_active = 1; // optional: ensure default is active
        $gateway->save();

        return response()->json([
            'message' => 'Default payment gateway updated.',
            'data' => $gateway,
        ]);
    }

    /**
     * Public-safe: Get active gateway for a school (used by parent checkout page)
     * Returns ONLY what frontend needs (NO secret_key).
     */
    public function getGatewayForSchool($school_id)
    {
        $gateway = PaymentGateway::where('school_id', $school_id)
            ->where('is_active', 1)
            ->orderByDesc('is_default')
            ->first();

        if (!$gateway) {
            return response()->json([
                'message' => 'Payment gateway not configured for this school'
            ], 404);
        }

        // Never expose secret keys to the frontend
        return response()->json([
            'id' => $gateway->id,
            'school_id' => $gateway->school_id,
            'provider' => $gateway->provider,
            'mode' => $gateway->mode,
            'public_key' => $gateway->public_key,
            'merchant_email' => $gateway->merchant_email,

            'channels' => $gateway->channels,
            'currency' => $gateway->currency,
            'country' => $gateway->country,

            // manual bank transfer support
            'bank_name' => $gateway->bank_name,
            'account_number' => $gateway->account_number,
            'account_name' => $gateway->account_name,

            'payment_url' => $gateway->payment_url,
            'config' => $gateway->config,

            'is_active' => (int) $gateway->is_active,
            'is_default' => (int) $gateway->is_default,
        ]);
    }
}