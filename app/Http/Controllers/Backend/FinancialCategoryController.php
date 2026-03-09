<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\FinancialCategory;
use Illuminate\Support\Facades\Auth;

class FinancialCategoryController extends Controller
{
    /**
     * List categories for authenticated school
     */
    public function index()
    {
        $schoolId = Auth::user()->school_id;

        $categories = FinancialCategory::where('school_id', $schoolId)
            ->orderBy('type')
            ->orderBy('name')
            ->get();

        return response()->json($categories);
    }

 
    /**
     * Store new financial category
     */
    public function store(Request $request)
    {
        $schoolId = Auth::user()->school_id;

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:income,expense',
        ]);

        // Prevent duplicate category per school
        $exists = FinancialCategory::where('school_id', $schoolId)
            ->where('name', $validated['name'])
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'Category already exists.'
            ], 422);
        }

        $category = FinancialCategory::create([
            'school_id' => $schoolId,
            'name' => $validated['name'],
            'type' => $validated['type'],
        ]);

        return response()->json($category, 201);
    }

   


    /**
     * Delete category
     */
    public function destroy($id)
    {
        $schoolId = Auth::user()->school_id;

        $category = FinancialCategory::where('school_id', $schoolId)
            ->findOrFail($id);

        $category->delete();

        return response()->json([
            'message' => 'Category deleted successfully.'
        ]);
    }
}

