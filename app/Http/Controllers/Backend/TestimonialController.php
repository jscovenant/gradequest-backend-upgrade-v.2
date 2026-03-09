<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\Testimonial;
use Illuminate\Http\Request;

class TestimonialController extends Controller
{
    public function index()
    {
        return response()->json(Testimonial::latest()->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'quote'  => 'required|string',
            'author' => 'required|string',
            'org'    => 'required|string',
            'img'    => 'nullable|url',
            'color'  => 'nullable|string',
            'rating' => 'required|integer|min:1|max:5',
        ]);

        $testimonial = Testimonial::create($validated);

        return response()->json($testimonial, 201);
    }

    // ✅ NEW: GET /testimonials/{id}
    public function edit($id)
    {
        $testimonial = Testimonial::findOrFail($id);
        return response()->json($testimonial);
    }

    // ✅ NEW: PUT /testimonials/{id}
    public function update(Request $request, $id)
    {
        $testimonial = Testimonial::findOrFail($id);

        $validated = $request->validate([
            'quote'  => 'required|string',
            'author' => 'required|string',
            'org'    => 'required|string',
            'img'    => 'nullable|url',
            'color'  => 'nullable|string',
            'rating' => 'required|integer|min:1|max:5',
        ]);

        $testimonial->update($validated);

        return response()->json([
            'message' => 'Updated successfully',
            'data'    => $testimonial
        ]);
    }

    public function destroy($id)
    {
        $testimonial = Testimonial::findOrFail($id);
        $testimonial->delete();

        return response()->json(['message' => 'Deleted successfully']);
    }
}
