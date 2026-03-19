<?php

namespace App\Services;

use App\Models\SchoolWhatsappAccount;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class SchoolWhatsappAccountService
{
    public function connect(User $authUser, array $data): SchoolWhatsappAccount
    {
        if (($authUser->role ?? null) !== 'Admin') {
            throw ValidationException::withMessages([
                'user' => 'Only admins can connect school WhatsApp business accounts.',
            ]);
        }

        $schoolId = (int) ($authUser->school_id ?? 0);

        if (!$schoolId) {
            throw ValidationException::withMessages([
                'school_id' => 'School not found for this admin.',
            ]);
        }

        return SchoolWhatsappAccount::updateOrCreate(
            ['school_id' => $schoolId],
            [
                'admin_user_id' => $authUser->id,
                
                'phone_number_id' => $data['phone_number_id'],
               
              
                'display_phone_number' => $data['display_phone_number'] ?? null,
                'verified_name' => $data['verified_name'] ?? null,
                'status' => $data['status'] ?? 'active',
                'connected_at' => now(),
                'meta_payload' => $data['meta_payload'] ?? null,
            ]
        );
    }
}