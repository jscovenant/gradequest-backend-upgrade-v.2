<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\SchoolBankAccount;
use Illuminate\Http\Request;

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
    $schoolId = $request->user()->school_id;

    $validated = $request->validate([
      'bank_name' => 'required|string|max:255',
      'bank_code' => 'nullable|string|max:20',
      'account_name' => 'required|string|max:255',
      'account_number' => 'required|string|max:20',
      'currency' => 'nullable|string|max:8',
      'is_active' => 'nullable|boolean',
      'sort_order' => 'nullable|integer|min:0|max:1000',
    ]);

    $item = SchoolBankAccount::create(array_merge($validated, [
      'school_id' => $schoolId,
      'currency' => $validated['currency'] ?? 'NGN',
      'is_active' => $validated['is_active'] ?? true,
      'sort_order' => $validated['sort_order'] ?? 0,
    ]));

    return response()->json($item, 201);
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
}