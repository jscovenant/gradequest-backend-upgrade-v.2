<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\SalesMarketingMaterial;
use App\Models\SalesPageEvent;
use App\Models\SalesRepAssignment;
use App\Models\SalesRepresentative;
use App\Services\SalesReferralService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PublicSalesPageController extends Controller
{
    public function __construct(private SalesReferralService $referrals)
    {
    }

    public function show(Request $request, string $code): JsonResponse
    {
        $rep = $this->activeRepresentative($code);
        $this->recordEvent($request, $rep, 'page_view');

        return response()->json([
            'representative' => $this->publicRepresentative($rep),
            'sales_page_url' => $this->referrals->salesPageUrl($rep->code),
            'materials' => SalesMarketingMaterial::currentlyActive()->latest()->get(),
        ]);
    }

    public function captureLead(Request $request, string $code): JsonResponse
    {
        $rep = $this->activeRepresentative($code);
        $data = $request->validate([
            'prospect_school_name' => ['required', 'string', 'max:180'],
            'contact_name' => ['required', 'string', 'max:160'],
            'contact_email' => ['required', 'email', 'max:180'],
            'contact_phone' => ['required', 'string', 'max:50'],
            'location' => ['nullable', 'string', 'max:180'],
            'expected_students' => ['nullable', 'integer', 'min:0'],
            'marketing_material_id' => ['nullable', 'integer', Rule::exists('sales_marketing_materials', 'id')->where('is_active', true)],
            'notes' => ['nullable', 'string', 'max:3000'],
        ]);

        $lead = SalesRepAssignment::query()
            ->where('sales_representative_id', $rep->id)
            ->whereNull('school_id')
            ->whereNull('admin_user_id')
            ->where(function ($query) use ($data) {
                $query->where('contact_email', $data['contact_email'])
                    ->orWhere('contact_phone', $data['contact_phone']);
            })
            ->where('created_at', '>=', now()->subDays(30))
            ->latest()
            ->first();

        $values = [
            'sales_representative_id' => $rep->id,
            'marketing_material_id' => $data['marketing_material_id'] ?? null,
            'prospect_school_name' => $data['prospect_school_name'],
            'contact_name' => $data['contact_name'],
            'contact_email' => $data['contact_email'],
            'contact_phone' => $data['contact_phone'],
            'location' => $data['location'] ?? null,
            'expected_students' => $data['expected_students'] ?? null,
            'stage' => 'lead',
            'source' => 'representative_sales_page',
            'notes' => $data['notes'] ?? null,
        ];

        if ($lead) {
            $lead->fill($values)->save();
        } else {
            $lead = SalesRepAssignment::create($values);
        }

        $this->recordEvent($request, $rep, 'lead_submitted', $data['marketing_material_id'] ?? null, ['lead_id' => $lead->id]);

        return response()->json([
            'message' => 'Your interest has been sent to the GradeQuest representative.',
            'lead_id' => $lead->id,
        ], $lead->wasRecentlyCreated ? 201 : 200);
    }

    public function track(Request $request, string $code): JsonResponse
    {
        $rep = $this->activeRepresentative($code);
        $data = $request->validate([
            'event_type' => ['required', Rule::in(['material_view', 'material_download', 'cta_click', 'whatsapp_click', 'registration_click'])],
            'marketing_material_id' => ['nullable', 'exists:sales_marketing_materials,id'],
        ]);

        $this->recordEvent($request, $rep, $data['event_type'], $data['marketing_material_id'] ?? null);

        return response()->json(['recorded' => true]);
    }

    private function activeRepresentative(string $code): SalesRepresentative
    {
        return SalesRepresentative::query()
            ->whereRaw('LOWER(code) = ?', [strtolower($code)])
            ->where('status', 'active')
            ->with('user:id,firstname,surname,email,phone,photo,status')
            ->firstOrFail();
    }

    private function publicRepresentative(SalesRepresentative $rep): array
    {
        return [
            'code' => $rep->code,
            'name' => trim(($rep->user?->firstname ?? '') . ' ' . ($rep->user?->surname ?? '')),
            'phone' => $rep->user?->phone,
            'photo' => $rep->user?->photo,
            'region' => $rep->region,
        ];
    }

    private function recordEvent(Request $request, SalesRepresentative $rep, string $type, ?int $materialId = null, array $metadata = []): void
    {
        SalesPageEvent::create([
            'sales_representative_id' => $rep->id,
            'marketing_material_id' => $materialId,
            'event_type' => $type,
            'visitor_hash' => hash('sha256', (string) $request->ip() . '|' . (string) $request->userAgent()),
            'referrer' => substr((string) $request->headers->get('referer'), 0, 255) ?: null,
            'user_agent' => substr((string) $request->userAgent(), 0, 500) ?: null,
            'metadata' => $metadata ?: null,
        ]);
    }
}
