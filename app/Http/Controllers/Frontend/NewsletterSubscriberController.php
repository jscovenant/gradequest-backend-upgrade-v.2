<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Models\NewsletterSubscriber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class NewsletterSubscriberController extends Controller
{
    /**
     * Handle public newsletter subscription.
     */
    public function subscribe(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'string', 'email:rfc,dns', 'max:255'],
            'source' => ['nullable', 'string', 'max:80'],
        ], [
            'email.required' => 'Please provide your email address.',
            'email.email' => 'Please provide a valid email address.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => $validator->errors()->first('email'),
                'errors' => $validator->errors(),
            ], 422);
        }

        $email = strtolower(trim($request->input('email')));
        $source = $request->input('source', 'homepage_footer');
        $ip = $request->ip();

        $subscriber = NewsletterSubscriber::where('email', $email)->first();

        if ($subscriber) {
            if ($subscriber->status === 'subscribed') {
                return response()->json([
                    'status' => 'success',
                    'message' => 'You are already subscribed to GradiosEdu platform updates! Thank you for staying connected.',
                    'data' => [
                        'email' => $subscriber->email,
                        'status' => $subscriber->status,
                        'is_new' => false,
                    ],
                ], 200);
            }

            // Reactivate unsubscribed user
            $subscriber->update([
                'status' => 'subscribed',
                'unsubscribed_at' => null,
                'source' => $source,
                'ip_address' => $ip,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Welcome back! Your subscription has been reactivated successfully.',
                'data' => [
                    'email' => $subscriber->email,
                    'status' => $subscriber->status,
                    'is_new' => false,
                ],
            ], 200);
        }

        $newSubscriber = NewsletterSubscriber::create([
            'email' => $email,
            'status' => 'subscribed',
            'source' => $source,
            'ip_address' => $ip,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Thank you for subscribing! You will receive our latest platform updates and educational insights.',
            'data' => [
                'email' => $newSubscriber->email,
                'status' => $newSubscriber->status,
                'is_new' => true,
            ],
        ], 201);
    }
}
