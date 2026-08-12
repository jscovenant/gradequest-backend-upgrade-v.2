<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\SalesMarketingMaterial;
use App\Models\SalesPageEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class SalesMarketingMaterialController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = SalesMarketingMaterial::query()->with('creator:id,firstname,surname,email')->latest();

        if ($request->boolean('active_only')) {
            $query->currentlyActive();
        }

        return response()->json([
            'materials' => $query->get(),
            'analytics' => [
                'page_views' => SalesPageEvent::where('event_type', 'page_view')->count(),
                'leads' => SalesPageEvent::where('event_type', 'lead_submitted')->count(),
                'material_engagements' => SalesPageEvent::whereIn('event_type', ['material_view', 'material_download', 'cta_click'])->count(),
            ],
        ]);
    }

    public function active(): JsonResponse
    {
        return response()->json([
            'materials' => SalesMarketingMaterial::currentlyActive()->latest()->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $material = SalesMarketingMaterial::create($this->validatedPayload($request) + [
            'created_by' => $request->user()?->id,
        ]);

        return response()->json(['message' => 'Marketing material published.', 'data' => $material], 201);
    }

    public function update(Request $request, SalesMarketingMaterial $material): JsonResponse
    {
        $payload = $this->validatedPayload($request, true);

        if (isset($payload['asset_path']) && $material->asset_path && $payload['asset_path'] !== $material->asset_path) {
            Storage::disk('public')->delete($material->asset_path);
        }

        $material->update($payload);

        return response()->json(['message' => 'Marketing material updated.', 'data' => $material->fresh()]);
    }

    public function destroy(SalesMarketingMaterial $material): JsonResponse
    {
        if ($material->asset_path) {
            Storage::disk('public')->delete($material->asset_path);
        }

        $material->delete();

        return response()->json(['message' => 'Marketing material removed.']);
    }

    private function validatedPayload(Request $request, bool $partial = false): array
    {
        $prefix = $partial ? 'sometimes' : 'required';
        $data = $request->validate([
            'title' => [$prefix, 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:3000'],
            'type' => [$prefix, Rule::in(['banner', 'flyer', 'video', 'copy'])],
            'asset' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf,mp4', 'max:20480'],
            'external_url' => ['nullable', 'url', 'max:1000'],
            'share_caption' => ['nullable', 'string', 'max:5000'],
            'cta_label' => ['nullable', 'string', 'max:80'],
            'cta_url' => ['nullable', 'url', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ]);

        if ($request->hasFile('asset')) {
            $data['asset_path'] = $request->file('asset')->store('sales-marketing', 'public');
        }

        unset($data['asset']);

        return $data;
    }
}
