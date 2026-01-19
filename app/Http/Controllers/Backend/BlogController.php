<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Blog;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;



class BlogController extends Controller
{
  
public function index()
{
    $blogs = Blog::orderBy('id', 'desc')->get();

    foreach ($blogs as $blog) {
        $blog->thumbnail_url = $blog->thumbnail
            ? asset($blog->thumbnail)
            : null;
    }

    return response()->json([
        "status" => true,
        "data" => $blogs
    ]);
}

public function edit($id)
{
    // Find blog or fail with 404
    $blog = Blog::findOrFail($id);

    // Prepare full URL for thumbnail if exists
    $blog->thumbnail_url = $blog->thumbnail ? asset($blog->thumbnail) : null;

    // Return blog data as JSON
    return response()->json([
        "status" => true,
        "data" => $blog
    ]);
}


  
public function update(Request $request, $id)
{
    $blog = Blog::findOrFail($id);

    $request->validate([
        "title" => "required",
        "category" => "required",
        "body" => "required",
        "thumbnail" => "nullable|image",
    ]);

    $thumbnailPath = $blog->thumbnail; // default: keep old file

    if ($request->hasFile("thumbnail")) {

        // Delete old file from public/blogs
        if ($blog->thumbnail && file_exists(public_path($blog->thumbnail))) {
            unlink(public_path($blog->thumbnail));
        }

        // Save new file directly to /public/blogs/
        $file = $request->file("thumbnail");
        $fileName = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();

        // Move the file to public/blogs
        $file->move(public_path('blogs'), $fileName);

        // Save the relative path (e.g., blogs/image.png)
        $thumbnailPath = 'blogs/' . $fileName;
    }

    $blog->update([
        "title" => $request->title,
        "slug" => Str::slug($request->title),
        "category" => $request->category,
        "youtube_url" => $request->youtube_url,
        "thumbnail" => $thumbnailPath,
        "excerpt" => substr(strip_tags($request->body), 0, 200),
        "body" => $request->body,
    ]);

    return response()->json([
        "message" => "Blog updated successfully!",
        "data" => $blog
    ]);
}

  
 public function store(Request $request)
{
    $request->validate([
        "title" => "required",
        "category" => "required",
        "body" => "nullable|string",
        "thumbnail" => "nullable|image",
    ]);

    $thumbnailPath = null;

    if ($request->hasFile("thumbnail")) {
        $file = $request->file("thumbnail");
        $fileName = uniqid() . '.' . $file->getClientOriginalExtension();

        // SAVE INTO /public/blogs
        $file->move(public_path('blogs'), $fileName);

        // Save database path as: blogs/filename.png
        $thumbnailPath = 'blogs/' . $fileName;
    }

    $blog = Blog::create([
        "title" => $request->title,
        "slug" => Str::slug($request->title),
        "category" => $request->category,
        "thumbnail" => $thumbnailPath,
        "youtube_url" => $request->youtube_url,
        "excerpt" => substr(strip_tags($request->body), 0, 200),
        "body" => $request->body,
    ]);

    return response()->json(["data" => $blog]);
}




// DELETE /blogs/{id}
    public function destroy($id)
    {
        $blog = Blog::find($id);

        if (!$blog) {
            return response()->json([
                "status" => false,
                "message" => "Blog not found"
            ], 404);
        }

        // delete thumbnail
        if ($blog->thumbnail && Storage::exists('public/' . $blog->thumbnail)) {
            Storage::delete('public/' . $blog->thumbnail);
        }

        $blog->delete();

        return response()->json([
            "status" => true,
            "message" => "Blog deleted successfully"
        ]);
    }
    
//frontnd method for showing blog

// BlogController.php

public function showBlogs($slug)
{
    $blog = Blog::where('slug', $slug)->firstOrFail();

    // Add full URL for thumbnail
    $blog->thumbnail_url = $blog->thumbnail ? asset($blog->thumbnail) : null;

    return response()->json([
        'status' => true,
        'data' => $blog
    ]);
}


}
