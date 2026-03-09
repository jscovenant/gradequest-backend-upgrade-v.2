<?php

namespace App\Http\Controllers\Backend;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\FinancialRecord;

class FinancialRecordController extends Controller
{
    
    public function store(Request $request)
{
    $validated = $request->validate([
        'category_id' => 'required|exists:financial_categories,id',
        'date' => 'required|date',
        'title' => 'required|string|max:255',
        'type' => 'required|in:income,expense',
        'amount' => 'required|numeric|min:0',
        'status' => 'required|in:paid,pending',
    ]);

    $validated['school_id'] = Auth::user()->school_id;

    $record = FinancialRecord::create($validated);

    return response()->json($record, 201);
}

public function update(Request $request, FinancialRecord $record)
{
    $validated = $request->validate([
        'category_id' => 'required|exists:financial_categories,id',
        'date' => 'required|date',
        'title' => 'required|string|max:255',
        'type' => 'required|in:income,expense',
        'amount' => 'required|numeric|min:0',
        'status' => 'required|in:paid,pending',
    ]);

    // Security: ensure school owns this record
    if ($record->school_id !== auth::user()->school_id) {
        return response()->json(['message' => 'Unauthorized'], 403);
    }

    $record->update($validated);

    return response()->json($record);
}

}
