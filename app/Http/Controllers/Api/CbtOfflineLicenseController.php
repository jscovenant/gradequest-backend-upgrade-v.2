<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CbtOfflineLicense;
use App\Models\SchoolSetting;
use App\Services\CbtAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CbtOfflineLicenseController extends Controller
{
    public function __construct(private readonly CbtAccessService $access)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $this->access->ensureCanUse($request->user(), 'offline');

        return response()->json([
            'licenses' => CbtOfflineLicense::query()
                ->where('school_id', $request->user()->school_id)
                ->latest()
                ->paginate((int) $request->query('per_page', 20)),
        ]);
    }

    public function generate(Request $request): JsonResponse
    {
        $this->access->ensureCanUse($request->user(), 'offline');

        $data = $request->validate([
            'max_students' => ['nullable', 'integer', 'min:0'],
            'max_exams' => ['nullable', 'integer', 'min:0'],
            'days' => ['required', 'integer', 'min:1', 'max:365'],
        ]);

        $school = SchoolSetting::findOrFail($request->user()->school_id);
        $payload = [
            'school_id' => $school->id,
            'school_name' => $school->school_name,
            'allowed_features' => ['cbt_offline'],
            'max_students' => (int) ($data['max_students'] ?? 0),
            'max_exams' => (int) ($data['max_exams'] ?? 0),
            'starts_at' => now()->toIso8601String(),
            'expires_at' => now()->addDays((int) $data['days'])->endOfDay()->toIso8601String(),
            'issued_at' => now()->toIso8601String(),
        ];

        $licenseKey = 'GQ-CBT-' . strtoupper(Str::random(24));
        $signature = hash_hmac('sha256', json_encode($payload), (string) config('app.key'));

        $license = CbtOfflineLicense::create([
            'school_id' => $school->id,
            'issued_by' => $request->user()->id,
            'license_key' => $licenseKey,
            'allowed_features' => $payload['allowed_features'],
            'max_students' => $payload['max_students'],
            'max_exams' => $payload['max_exams'],
            'starts_at' => now(),
            'expires_at' => now()->addDays((int) $data['days'])->endOfDay(),
            'status' => 'active',
            'signature' => $signature,
            'payload' => $payload,
        ]);

        return response()->json([
            'message' => 'Offline CBT license generated.',
            'license' => $license,
            'download_payload' => [
                'license_key' => $licenseKey,
                'payload' => $payload,
                'signature' => $signature,
            ],
        ], 201);
    }

    public function revoke(Request $request, CbtOfflineLicense $license): JsonResponse
    {
        abort_unless((int) $license->school_id === (int) $request->user()->school_id, 403);
        $this->access->ensureCanUse($request->user(), 'offline');

        $license->update(['status' => 'revoked']);

        return response()->json([
            'message' => 'Offline CBT license revoked.',
            'license' => $license->fresh(),
        ]);
    }
}
