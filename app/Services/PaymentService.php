<?php

namespace App\Services;

use App\Models\User;
use App\Models\Payment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\RedirectResponse;


class PaymentService
{

    public function CheckForPaymentStatus()
    {

        $auth = Auth::user();
        //checking if the user has a subscription plan
        $payment = Payment::where("school_id", $auth->school_id)->latest()->first();
        $users = User::where("school_id", $auth->school_id)->where("role", 'Student')->get();

        if ($auth->role == "Admin" || $auth->role == "Teacher") {

            if (!$payment) {
                return redirect()->route('checkout')->with('error', 'You are not on any payment plan. Subscribe below!');
            }

            $now = Carbon::now();
            $registrationTime = Carbon::parse($payment->created_at);

         $monthlyExpiry = $registrationTime->copy()->addDays((int) ($payment->monthly_duration ?? 0));
$yearlyExpiry = $registrationTime->copy()->addDays((int) ($payment->yearly_duration ?? 0));


            // return $monthlyExpiry;
            if (empty($payment->yearly_duration) && $now->isAfter($monthlyExpiry)) {
                return redirect()->route('checkout')->with('error', 'Your monthly payment plan has expired. Please renew your subscription.');
            }


            if (empty($payment->monthly_duration) && $now->isAfter($yearlyExpiry)) {
                return redirect()->route('checkout')->with('error', 'Your yearly payment plan has expired. Please renew your subscription.');
            }

            // Check if the payment quantity is "Unlimited"
            if (strtolower($payment->quantity) === "unlimited") {
                return null;
            }

            if (count($users) >= (int)$payment->quantity) {
                return redirect()->route('checkout')->with('error', 'You have exceeded the number of students for this plan. Please upgrade.');
            }
        }
    }
}
