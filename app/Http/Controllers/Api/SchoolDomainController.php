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

    public function tlsAllowed(Request $request)
    {
        $configuredSecret = (string) config('domains.tls_ask_secret');
        $providedSecret = (string) $request->query('token');

        abort_unless($configuredSecret !== '' && hash_equals($configuredSecret, $providedSecret), 403);

        $domain = strtolower(trim((string) $request->query('domain'), '. '));
        abort_unless(
            $domain !== '' && SchoolDomain::query()->where('domain', $domain)->where('status', 'active')->exists(),
            404
        );

        return response()->noContent();
    }

    public function show(Request $request)
    {
        $this->authorizeSchoolAdministrator($request);

        $domain = SchoolDomain::where('school_id', $request->user()->school_id)
            ->latest()->first();

        return response()->json(['data' => $domain, 'instructions' => $domain ? $this->service->instructions($domain) : null]);
    }

    public function register(Request $request)
    {
        $this->authorizeSchoolAdministrator($request);

        $request->validate(['domain' => ['required', 'string']]);

        $school = SchoolSetting::findOrFail($request->user()->school_id);
        $record = $this->service->register($school, $request->domain);

        return response()->json(['data' => $record, 'instructions' => $this->service->instructions($record)], 201);
    }

    public function verify(Request $request)
    {
        $this->authorizeSchoolAdministrator($request);

        $request->validate(['domain_id' => ['required', 'integer']]);

        $record = SchoolDomain::where('id', $request->domain_id)
            ->where('school_id', $request->user()->school_id)
            ->firstOrFail();

        $record = $this->service->verifyOwnership($record);

        return response()->json(['data' => $record, 'instructions' => $this->service->instructions($record)]);
    }

    public function activate(Request $request)
    {
        $this->authorizeSchoolAdministrator($request);

        $request->validate(['domain_id' => ['required', 'integer']]);

        $record = SchoolDomain::query()
            ->whereKey($request->integer('domain_id'))
            ->where('school_id', $request->user()->school_id)
            ->firstOrFail();

        $record = $this->service->activate($record);

        return response()->json(['data' => $record, 'instructions' => $this->service->instructions($record)]);
    }

    public function remove(Request $request, int $id)
    {
        $this->authorizeSchoolAdministrator($request);

        $record = SchoolDomain::where('id', $id)
            ->where('school_id', $request->user()->school_id)
            ->firstOrFail();

        SchoolSetting::query()->whereKey($request->user()->school_id)->update(['custom_domain' => null]);
        $record->delete();

        return response()->json(['message' => 'Domain removed.']);
    }

    private function authorizeSchoolAdministrator(Request $request): void
    {
        abort_unless(
            $request->user()?->role === 'Admin' && $request->user()?->school_id,
            403,
            'Only a school administrator can manage the school domain.'
        );
    }
}
