<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
    use App\Mail\ContactFormMail;

class ContactController extends Controller
{
  
    
    
    public function send(Request $request)
    {
        $validated = $request->validate([
            'first_name' => 'required|string',
            'last_name' => 'required|string',
            'email' => 'required|email',
            'phone' => 'required|string',
            'school' => 'required|string',
            'description' => 'required|string',
            'message' => 'required|string',
        ]);
    
        Mail::to('contact@gradequest.com.ng')->send(new ContactFormMail($validated));
    
        return response()->json(['success' => true, 'message' => 'Message sent successfully!']);
    }
    
}
