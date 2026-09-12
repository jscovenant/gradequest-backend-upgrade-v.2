<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\SalesRepresentative;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class MarketingBrochureController extends Controller
{
    public function download(Request $request)
    {
        $user = $request->user();
        if (!$user && $request->filled('token')) {
            $token = \Laravel\Sanctum\PersonalAccessToken::findToken($request->query('token'));
            if ($token) {
                $user = $token->tokenable;
            }
        }

        $rep = null;

        if ($user) {
            if ($user->role === 'Sales-Representative') {
                $rep = SalesRepresentative::where('user_id', $user->id)->first();
            } elseif ($request->filled('rep_code')) {
                $rep = SalesRepresentative::where('code', $request->query('rep_code'))->where('status', 'active')->first();
            }
        } elseif ($request->filled('rep_code')) {
            $rep = SalesRepresentative::where('code', $request->query('rep_code'))->where('status', 'active')->first();
        }

        $data = [
            'rep_name' => $rep ? trim($rep->first_name . ' ' . $rep->last_name) : ($request->query('rep_name') ?: 'Official SchoolProfit Representative'),
            'rep_phone' => $rep ? ($rep->phone ?: '+234 814 972 9948') : ($request->query('rep_phone') ?: '+234 814 972 9948'),
            'rep_email' => $rep ? ($rep->email ?: 'hello@schoolprofit.ng') : ($request->query('rep_email') ?: 'hello@schoolprofit.ng'),
            'rep_code' => $rep ? $rep->code : ($request->query('rep_code') ?: null),
        ];

        $pdf = Pdf::loadView('pdf.marketing-brochure', $data)
            ->setPaper('a4', 'portrait')
            ->setOption([
                'isHtml5ParserEnabled' => true,
                'isRemoteEnabled' => true,
                'defaultFont' => 'sans-serif',
            ]);

        $fileName = 'SchoolProfit-Official-Marketing-Brochure' . ($rep ? '-' . $rep->code : '') . '.pdf';

        if ($request->boolean('preview') || $request->boolean('stream') || $request->query('view') === 'inline') {
            return $pdf->stream($fileName);
        }

        return $pdf->download($fileName);
    }

    public function publicDownload(Request $request)
    {
        return $this->download($request);
    }
}
