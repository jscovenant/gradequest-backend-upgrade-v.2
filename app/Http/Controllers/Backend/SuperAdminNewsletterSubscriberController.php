<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\NewsletterSubscriber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SuperAdminNewsletterSubscriberController extends Controller
{
    /**
     * Display a paginated listing of subscribers with search and metrics.
     */
    public function index(Request $request): JsonResponse
    {
        $search = trim($request->input('search', ''));
        $status = trim($request->input('status', 'all'));
        $perPage = max(5, min(100, (int) $request->input('per_page', 20)));

        $query = NewsletterSubscriber::query()->latest();

        if (!empty($search)) {
            $query->where('email', 'like', "%{$search}%");
        }

        if ($status === 'subscribed') {
            $query->where('status', 'subscribed');
        } elseif ($status === 'unsubscribed') {
            $query->where('status', 'unsubscribed');
        }

        $subscribers = $query->paginate($perPage);

        // Calculate summary metrics
        $totalCount = NewsletterSubscriber::count();
        $subscribedCount = NewsletterSubscriber::where('status', 'subscribed')->count();
        $unsubscribedCount = NewsletterSubscriber::where('status', 'unsubscribed')->count();
        $last30DaysCount = NewsletterSubscriber::where('created_at', '>=', now()->subDays(30))->count();

        return response()->json([
            'status' => 'success',
            'data' => $subscribers,
            'metrics' => [
                'total' => $totalCount,
                'subscribed' => $subscribedCount,
                'unsubscribed' => $unsubscribedCount,
                'recent_30_days' => $last30DaysCount,
            ],
        ]);
    }

    /**
     * Toggle subscriber status between subscribed and unsubscribed.
     */
    public function toggleStatus(int $id): JsonResponse
    {
        $subscriber = NewsletterSubscriber::findOrFail($id);

        if ($subscriber->status === 'subscribed') {
            $subscriber->update([
                'status' => 'unsubscribed',
                'unsubscribed_at' => now(),
            ]);
            $msg = 'Subscriber marked as unsubscribed.';
        } else {
            $subscriber->update([
                'status' => 'subscribed',
                'unsubscribed_at' => null,
            ]);
            $msg = 'Subscriber status reactivated.';
        }

        return response()->json([
            'status' => 'success',
            'message' => $msg,
            'data' => $subscriber,
        ]);
    }

    /**
     * Delete a subscriber permanently.
     */
    public function destroy(int $id): JsonResponse
    {
        $subscriber = NewsletterSubscriber::findOrFail($id);
        $email = $subscriber->email;
        $subscriber->delete();

        return response()->json([
            'status' => 'success',
            'message' => "Subscriber '{$email}' removed successfully.",
        ]);
    }

    /**
     * Export all subscribers to CSV.
     */
    public function export(Request $request): StreamedResponse
    {
        $subscribers = NewsletterSubscriber::orderBy('id', 'desc')->get();

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="gradequest_newsletter_subscribers_' . date('Y_m_d_His') . '.csv"',
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        return response()->stream(function () use ($subscribers) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['ID', 'Email', 'Status', 'Source', 'IP Address', 'Subscribed Date', 'Unsubscribed Date']);

            foreach ($subscribers as $row) {
                fputcsv($handle, [
                    $row->id,
                    $row->email,
                    $row->status,
                    $row->source,
                    $row->ip_address,
                    $row->created_at ? $row->created_at->format('Y-m-d H:i:s') : '',
                    $row->unsubscribed_at ? $row->unsubscribed_at->format('Y-m-d H:i:s') : '',
                ]);
            }

            fclose($handle);
        }, 200, $headers);
    }
}
