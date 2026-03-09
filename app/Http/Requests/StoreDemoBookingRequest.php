<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDemoBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'firstName' => ['required', 'string', 'max:100'],
            'lastName' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150'],
            'phone' => ['required', 'string', 'max:30'],
            'role' => ['required', 'string', 'max:100'],
            'schoolName' => ['required', 'string', 'max:150'],
            'schoolType' => ['required', 'string', 'max:100'],
            'studentCount' => ['required', 'string', 'max:50'],
            'date' => ['required', 'date', 'after:today'],
            'time' => ['required', 'string', 'max:20'],
            'message' => ['nullable', 'string', 'max:2000'],
        ];
    }
}