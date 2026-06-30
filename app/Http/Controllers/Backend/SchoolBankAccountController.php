<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\SchoolBankAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SchoolBankAccountController extends Controller
{
  // School admin: list own bank accounts
  public function index(Request $request)
  {
    $schoolId = $request->user()->school_id;

    $items = SchoolBankAccount::where('school_id', $schoolId)
      ->orderByDesc('is_active')
      ->orderBy('sort_order')
      ->latest()
      ->get();

    return response()->json($items);
  }

  // School admin: create


public function store(Request $request)
{
    $school = $request->user()->school;

    $validated = $request->validate([
        'bank_name'       => 'required|string|max:255',
        'bank_code'       => 'required|string|max:20',
        'account_name'    => 'required|string|max:255',
        'account_number'  => 'required|string|max:20',
        'currency'        => 'nullable|string|max:8',
        'is_active'       => 'nullable|boolean',
        'sort_order'      => 'nullable|integer|min:0|max:1000',
    ]);

    DB::beginTransaction();

    try {

        /**
         * STEP 1
         * Verify the account number.
         */
        $verify = Http::withToken(config('services.paystack.secret'))
            ->get('https://api.paystack.co/bank/resolve', [
                'account_number' => $validated['account_number'],
                'bank_code'      => $validated['bank_code'],
            ]);

        if (! $verify->successful() || ! $verify->json('status')) {
            throw new \Exception('Invalid bank account.');
        }

        $resolvedName = $verify->json('data.account_name');

        /**
         * Optional:
         * Replace whatever user typed with the verified account name.
         */
        $validated['account_name'] = $resolvedName;

        /**
         * STEP 2
         * Create Paystack Subaccount.
         */
        $subaccount = Http::withToken(config('services.paystack.secret'))
            ->post('https://api.paystack.co/subaccount', [
                'business_name'     => $school->name,
                'settlement_bank'   => $validated['bank_code'],
                'account_number'    => $validated['account_number'],
                'percentage_charge' => 0,
            ]);

        if (! $subaccount->successful() || ! $subaccount->json('status')) {
            throw new \Exception(
                $subaccount->json('message') ?? 'Unable to create Paystack subaccount.'
            );
        }

        /**
         * STEP 3
         * Save everything.
         */

        $existing = SchoolBankAccount::where('school_id', $school->id)->first();

          if ($existing) {
              return response()->json([
                  'message' => 'A bank account has already been added. Please update the existing account instead.'
              ], 422);
          }


        $item = SchoolBankAccount::create([
            'school_id' => $school->id,
            'bank_name' => $validated['bank_name'],
            'bank_code' => $validated['bank_code'],
            'account_name' => $resolvedName,
            'account_number' => $validated['account_number'],
            'currency' => $validated['currency'] ?? 'NGN',
            'is_active' => $validated['is_active'] ?? true,
            'sort_order' => $validated['sort_order'] ?? 0,
            'paystack_subaccount_code' => $subaccount->json('data.subaccount_code'),
        ]);

        DB::commit();

        return response()->json([
            'message' => 'Bank account verified and connected successfully.',
            'data' => $item,
        ], 201);

    } catch (\Throwable $e) {

        DB::rollBack();

        return response()->json([
            'message' => $e->getMessage()
        ], 422);
    }
}



public function verifyAccount(Request $request)
{
    $validated = $request->validate([
        'bank_code' => 'required|string',
        'account_number' => 'required|string|size:10',
    ]);

    $response = Http::withToken(config('services.paystack.secret'))
        ->get('https://api.paystack.co/bank/resolve', [
            'account_number' => $validated['account_number'],
            'bank_code' => $validated['bank_code'],
        ]);

      

    if (! $response->successful() || ! $response->json('status')) {
        return response()->json([
            'status' => false,
            'message' => 'Invalid bank account.',
        ], 422);
    }

    $data = $response->json('data');

    return response()->json([
        'status' => true,
        'account_name' => $data['account_name'],
        'account_number' => $data['account_number'],
        'bank_id' => $data['bank_id'] ?? null,
    ]);
}

  // School admin: update
  public function update(Request $request, $id)
  {
    $schoolId = $request->user()->school_id;

    $item = SchoolBankAccount::where('school_id', $schoolId)->findOrFail($id);

    $validated = $request->validate([
      'bank_name' => 'sometimes|required|string|max:255',
      'bank_code' => 'nullable|string|max:20',
      'account_name' => 'sometimes|required|string|max:255',
      'account_number' => 'sometimes|required|string|max:20',
      'currency' => 'nullable|string|max:8',
      'is_active' => 'nullable|boolean',
      'sort_order' => 'nullable|integer|min:0|max:1000',
    ]);

    $item->update($validated);

    return response()->json([
      'message' => 'Bank account updated.',
      'data' => $item,
    ]);
  }

  // School admin: delete
  public function destroy(Request $request, $id)
  {
    $schoolId = $request->user()->school_id;

    $item = SchoolBankAccount::where('school_id', $schoolId)->findOrFail($id);
    $item->delete();

    return response()->json(['message' => 'Bank account deleted.']);
  }

  // Parent / public within auth: get active accounts for a school
  public function activeForSchool(Request $request, $schoolId)
  {
    $items = SchoolBankAccount::where('school_id', $schoolId)
      ->where('is_active', true)
      ->orderBy('sort_order')
      ->get(['id','bank_name','bank_code','account_name','account_number','currency']);

    return response()->json($items);
  }


  public function banks()
{
    $response = Http::withToken(config('services.paystack.secret'))
        ->get('https://api.paystack.co/bank', [
            'country' => 'nigeria',
        ]);

    if (! $response->successful()) {
        return response()->json([
            'message' => 'Unable to fetch banks.'
        ], 500);
    }

    return response()->json($response->json('data'));
}


}