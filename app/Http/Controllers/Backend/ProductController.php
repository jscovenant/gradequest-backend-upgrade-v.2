<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Product;
    
    

class ProductController extends Controller
{
    
   
        public function index(Request $request)
        {
            $perPage = $request->get('per_page', 10);
    
            $products = Product::orderBy('created_at', 'desc')->paginate($perPage);
    
            return response()->json($products);
        }


        public function show($id)
        {
            $product = Product::find($id);
    
            if (!$product) {
                return response()->json(['message' => 'Product not found'], 404);
            }
    
            return response()->json($product);
        }
    
        public function update(Request $request, $id)
        {
            $product = Product::find($id);
    
            if (!$product) {
                return response()->json(['message' => 'Product not found'], 404);
            }
    
            $request->validate([
                'name' => 'required|string|max:255',
                'description' => 'required|string',
                'price' => 'required|numeric|min:0',
            ]);
    
            $product->name = $request->name;
            $product->description = $request->description;
            $product->price = $request->price;
            $product->save();
    
            return response()->json(['message' => 'Product updated successfully', 'product' => $product]);
        }
    
}
