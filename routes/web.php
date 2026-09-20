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

Route::get('/robots.txt', function () {
    $content = "User-agent: *\n"
        . "Allow: /\n"
        . "Allow: /book-demo\n"
        . "Allow: /register\n"
        . "Allow: /become-a-partner\n"
        . "Allow: /sales-representative/register\n"
        . "Allow: /login\n"
        . "Allow: /check-result\n"
        . "Allow: /verify-result\n"
        . "Allow: /pay-fees\n"
        . "Allow: /cbt/access\n"
        . "Allow: /privacy-policy\n"
        . "Allow: /terms-and-conditions\n"
        . "Allow: /blog/\n"
        . "Allow: /sales-page/\n"
        . "Allow: /brochure.pdf\n"
        . "Allow: /SchoolProfit-Comprehensive-Platform-Guide.docx\n"
        . "Allow: /marketing/\n"
        . "\n"
        . "Disallow: /admin/\n"
        . "Disallow: /superadmin/\n"
        . "Disallow: /teacher/\n"
        . "Disallow: /student/\n"
        . "Disallow: /parent/\n"
        . "Disallow: /bursar/\n"
        . "Disallow: /api/\n"
        . "Disallow: /dashboard\n"
        . "Disallow: /dashboard/\n"
        . "Disallow: /onboarding\n"
        . "Disallow: /change-password\n"
        . "Disallow: /forgot-password\n"
        . "Disallow: /reset-password\n\n"
        . "Sitemap: https://schoolprofit.ng/sitemap.xml\n";

    return response($content, 200, ['Content-Type' => 'text/plain']);
});

Route::get('/sitemap.xml', function () {
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" '
        . 'xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";

    $staticPages = [
        ['url' => 'https://schoolprofit.ng/', 'priority' => '1.00', 'freq' => 'daily'],
        ['url' => 'https://schoolprofit.ng/book-demo', 'priority' => '0.90', 'freq' => 'weekly'],
        ['url' => 'https://schoolprofit.ng/register', 'priority' => '0.90', 'freq' => 'weekly'],
        ['url' => 'https://schoolprofit.ng/become-a-partner', 'priority' => '0.85', 'freq' => 'weekly'],
        ['url' => 'https://schoolprofit.ng/sales-representative/register', 'priority' => '0.85', 'freq' => 'weekly'],
        ['url' => 'https://schoolprofit.ng/check-result', 'priority' => '0.80', 'freq' => 'monthly'],
        ['url' => 'https://schoolprofit.ng/verify-result', 'priority' => '0.75', 'freq' => 'monthly'],
        ['url' => 'https://schoolprofit.ng/pay-fees', 'priority' => '0.80', 'freq' => 'monthly'],
        ['url' => 'https://schoolprofit.ng/cbt/access', 'priority' => '0.75', 'freq' => 'monthly'],
        ['url' => 'https://schoolprofit.ng/cbt/offline-runner', 'priority' => '0.70', 'freq' => 'monthly'],
        ['url' => 'https://schoolprofit.ng/login', 'priority' => '0.70', 'freq' => 'monthly'],
        ['url' => 'https://schoolprofit.ng/privacy-policy', 'priority' => '0.50', 'freq' => 'yearly'],
        ['url' => 'https://schoolprofit.ng/terms-and-conditions', 'priority' => '0.50', 'freq' => 'yearly'],
        ['url' => 'https://schoolprofit.ng/brochure.pdf', 'priority' => '0.80', 'freq' => 'weekly'],
        ['url' => 'https://schoolprofit.ng/downloads/SchoolProfit-Comprehensive-Platform-Guide.docx', 'priority' => '0.75', 'freq' => 'weekly'],
    ];

    $today = date('Y-m-d');
    foreach ($staticPages as $page) {
        $xml .= "  <url>\n";
        $xml .= "    <loc>{$page['url']}</loc>\n";
        $xml .= "    <lastmod>{$today}</lastmod>\n";
        $xml .= "    <changefreq>{$page['freq']}</changefreq>\n";
        $xml .= "    <priority>{$page['priority']}</priority>\n";
        if ($page['url'] === 'https://schoolprofit.ng/') {
            $xml .= "    <image:image>\n";
            $xml .= "      <image:loc>https://schoolprofit.ng/marketing/schoolprofit-flagship-banner.png</image:loc>\n";
            $xml .= "      <image:title>SchoolProfit - The School Growth and Profit Operating System</image:title>\n";
            $xml .= "    </image:image>\n";
        }
        $xml .= "  </url>\n";
    }

    // Dynamic Active Blogs
    if (\Illuminate\Support\Facades\Schema::hasTable('blogs')) {
        $blogs = \App\Models\Blog::where('status', 'published')->latest()->take(100)->get();
        foreach ($blogs as $blog) {
            $slug = $blog->slug ?: $blog->id;
            $mod = $blog->updated_at ? $blog->updated_at->format('Y-m-d') : $today;
            $xml .= "  <url>\n";
            $xml .= "    <loc>https://schoolprofit.ng/blog/{$slug}</loc>\n";
            $xml .= "    <lastmod>{$mod}</lastmod>\n";
            $xml .= "    <changefreq>weekly</changefreq>\n";
            $xml .= "    <priority>0.70</priority>\n";
            $xml .= "  </url>\n";
        }
    }

    // Dynamic Active Sales Representative Pages
    if (\Illuminate\Support\Facades\Schema::hasTable('sales_representatives')) {
        $reps = \App\Models\SalesRepresentative::where('status', 'active')->get();
        foreach ($reps as $rep) {
            $xml .= "  <url>\n";
            $xml .= "    <loc>https://schoolprofit.ng/sales-page/{$rep->code}</loc>\n";
            $xml .= "    <lastmod>{$today}</lastmod>\n";
            $xml .= "    <changefreq>weekly</changefreq>\n";
            $xml .= "    <priority>0.65</priority>\n";
            $xml .= "  </url>\n";
        }
    }

    $xml .= '</urlset>';

    return response($xml, 200, ['Content-Type' => 'application/xml']);
});