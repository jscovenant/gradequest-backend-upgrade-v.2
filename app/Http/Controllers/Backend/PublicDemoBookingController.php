<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDemoBookingRequest;
use App\Mail\DemoBookingConfirmationMail;
use App\Models\DemoBooking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class PublicDemoBookingController extends Controller
{
    public function store(StoreDemoBookingRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Optional duplicate protection:
        $existing = DemoBooking::where('email', $data['email'])
            ->where('preferred_date', $data['date'])
            ->where('preferred_time', $data['time'])
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'A booking already exists for this email on the selected date and time.',
            ], 422);
        }

        $booking = DemoBooking::create([
            'first_name'     => $data['firstName'],
            'last_name'      => $data['lastName'],
            'email'          => $data['email'],
            'phone'          => $data['phone'],
            'role'           => $data['role'],
            'school_name'    => $data['schoolName'],
            'school_type'    => $data['schoolType'],
            'student_count'  => $data['studentCount'],
            'preferred_date' => $data['date'],
            'preferred_time' => $data['time'],
            'message'        => $data['message'] ?? null,
            'status'         => 'pending',
            'source'         => 'website',
        ]);

            try {
            Mail::to($booking->email)->send(new DemoBookingConfirmationMail($booking));
        } catch (\Throwable $e) {
            Log::error('Demo booking confirmation mail failed', [
                'booking_id' => $booking->id,
                'email' => $booking->email,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'message' => 'Demo booking submitted successfully.',
            'data' => [
                'id' => $booking->id,
                'first_name' => $booking->first_name,
                'last_name' => $booking->last_name,
                'email' => $booking->email,
                'school_name' => $booking->school_name,
                'preferred_date' => $booking->preferred_date,
                'preferred_time' => $booking->preferred_time,
                'status' => $booking->status,
            ],
        ], 201);
    }


     /**
     * Protected dashboard list
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->get('per_page', 10);
        $search = trim((string) $request->get('search', ''));
        $status = trim((string) $request->get('status', ''));
        $date = trim((string) $request->get('date', ''));

        $query = DemoBooking::query()
            ->select([
                'id',
                'first_name',
                'last_name',
                'email',
                'phone',
                'role',
                'school_name',
                'school_type',
                'student_count',
                'preferred_date',
                'preferred_time',
                'message',
                'status',
                'source',
                'created_at',
            ])
            ->latest();

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('school_name', 'like', "%{$search}%");
            });
        }

        if ($status !== '') {
            $query->where('status', $status);
        }

        if ($date !== '') {
            $query->whereDate('preferred_date', $date);
        }

        $bookings = $query->paginate($perPage);

        $bookings->getCollection()->transform(function ($item) {
            return [
                'id' => $item->id,
                'full_name' => trim($item->first_name . ' ' . $item->last_name),
                'first_name' => $item->first_name,
                'last_name' => $item->last_name,
                'email' => $item->email,
                'phone' => $item->phone,
                'role' => $item->role,
                'school_name' => $item->school_name,
                'school_type' => $item->school_type,
                'student_count' => $item->student_count,
                'preferred_date' => $item->preferred_date,
                'preferred_time' => $item->preferred_time,
                'message' => $item->message,
                'status' => $item->status,
                'source' => $item->source,
                'created_at' => optional($item->created_at)?->toDateTimeString(),
            ];
        });

        return response()->json($bookings);
    }

    /**
     * Protected single booking view
     */
    public function show(int $id): JsonResponse
    {
        $booking = DemoBooking::findOrFail($id);

        return response()->json([
            'data' => [
                'id' => $booking->id,
                'full_name' => trim($booking->first_name . ' ' . $booking->last_name),
                'first_name' => $booking->first_name,
                'last_name' => $booking->last_name,
                'email' => $booking->email,
                'phone' => $booking->phone,
                'role' => $booking->role,
                'school_name' => $booking->school_name,
                'school_type' => $booking->school_type,
                'student_count' => $booking->student_count,
                'preferred_date' => $booking->preferred_date,
                'preferred_time' => $booking->preferred_time,
                'message' => $booking->message,
                'status' => $booking->status,
                'source' => $booking->source,
                'created_at' => optional($booking->created_at)?->toDateTimeString(),
            ]
        ]);
    }

    /**
     * Optional: update status from dashboard
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'in:pending,confirmed,cancelled,completed'],
        ]);

        $booking = DemoBooking::findOrFail($id);
        $booking->status = $validated['status'];
        $booking->save();

        return response()->json([
            'message' => 'Booking status updated successfully.',
            'data' => $booking,
        ]);
    }
}