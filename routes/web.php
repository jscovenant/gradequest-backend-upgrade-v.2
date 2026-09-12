<?php

use App\Http\Controllers\Backend\MarketingBrochureController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/brochure.pdf', [MarketingBrochureController::class, 'publicDownload']);
Route::get('/downloads/schoolprofit-marketing-brochure.pdf', [MarketingBrochureController::class, 'publicDownload']);
Route::get('/SchoolProfit-Comprehensive-Platform-Guide.docx', [MarketingBrochureController::class, 'downloadDocxManual']);
Route::get('/downloads/SchoolProfit-Comprehensive-Platform-Guide.docx', [MarketingBrochureController::class, 'downloadDocxManual']);