<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SchoolDomain;
use App\Models\SchoolSetting;
use Illuminate\Http\Request;
use App\Services\SchoolDomainService;

class SchoolDomainController extends Controller
{
    public function __construct(private SchoolDomainService $service) {}

    public function show(Request $request)
    {
        $domain = SchoolDomain::where('school_id', $request->user()->school_id)
            ->latest()->first();

        return response()->json(['data' => $domain]);
    }

    public function register(Request $request)
    {
        $request->validate(['domain' => ['required', 'string']]);

        $school = SchoolSetting::findOrFail($request->user()->school_id);
        $record = $this->service->register($school, $request->domain);

        return response()->json(['data' => $record], 201);
    }

    public function verify(Request $request)
    {
        $request->validate(['domain_id' => ['required', 'integer']]);

        $record = SchoolDomain::where('id', $request->domain_id)
            ->where('school_id', $request->user()->school_id)
            ->firstOrFail();

        $this->service->verify($record);

        return response()->json(['data' => $record->fresh()]);
    }

    public function remove(Request $request, int $id)
    {
        SchoolDomain::where('id', $id)
            ->where('school_id', $request->user()->school_id)
            ->firstOrFail()
            ->delete();

        return response()->json(['message' => 'Domain removed.']);
    }
}
