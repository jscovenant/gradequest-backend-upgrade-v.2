<?php

namespace App\Http\Controllers;

use App\Models\SchoolsLogo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SchoolLogoController extends Controller
{
    public function getLogos()
{
    return response()->json(SchoolsLogo::all());
}


    public function saveLogo(Request $request)
    {
        try {
            Log::info('Store method called');
    
            $request->validate([
                'image' => 'required|image|mimes:jpg,jpeg,png,gif|max:2048',
            ]);
    
            if ($request->hasFile('image')) {
                $image = $request->file('image');
                $imageData = base64_encode(file_get_contents($image));
    
                $response = Http::asForm()->post('https://api.imgbb.com/1/upload', [
                    'key' => 'a1616000221fbdbe0523cba9a9eb1945',
                    'image' => $imageData,
                ]);
    
                if ($response->successful()) {
                    $imageUrl = $response->json()['data']['url'];
                    Log::info('Image uploaded to ImgBB: ' . $imageUrl);
    
                    // ✅ Save to DB
                    $logo = new SchoolsLogo();
                    $logo->logo_url = $imageUrl;
                    $logo->save();
    
                    return response()->json([
                        'message' => 'Image uploaded and saved successfully',
                        'url' => $imageUrl,
                    ]);
                } else {
                    Log::error('ImgBB upload failed: ' . $response->body());
                    return response()->json(['error' => 'Failed to upload image to ImgBB'], 500);
                }
            }
    
            return response()->json(['error' => 'No image file found'], 400);
        } catch (\Exception $e) {
            Log::error('Error uploading image: ' . $e->getMessage());
            return response()->json(['error' => 'Server Error'], 500);
        }
    }
    
}
