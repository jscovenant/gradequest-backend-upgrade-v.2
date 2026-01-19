<?php
use Illuminate\Support\Facades\Route;
    
use App\Models\QrCode;

Route::middleware('guest')->group(function () {
    Route::get('/', function () {
        return view('welcome');
    });
    

});