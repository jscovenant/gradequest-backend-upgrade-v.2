<?php

namespace App\Support;

use App\Models\SchoolSetting;
use App\Models\User;
use Illuminate\Http\Request;

class CurrentSchool
{
    private ?SchoolSetting $school = null;

    public function __construct(private readonly Request $request)
    {
    }

    public function set(?SchoolSetting $school): void
    {
        $this->school = $school;

        if ($school) {
            app()->instance('current_school', $school);
            $this->request->attributes->set('school', $school);
        }
    }

    public function get(): ?SchoolSetting
    {
        if ($this->school) {
            return $this->school;
        }

        $resolved = $this->request->attributes->get('school');

        if (! $resolved && app()->bound('current_school')) {
            $resolved = app('current_school');
        }

        if ($resolved instanceof SchoolSetting) {
            return $this->school = $resolved;
        }

        $user = $this->request->user();

        if ($user instanceof User && ! $this->isPlatformUser($user) && $user->school_id) {
            return $this->school = SchoolSetting::find($user->school_id);
        }

        return null;
    }

    public function id(): ?int
    {
        return $this->get()?->id;
    }

    public function isPlatformUser(?User $user = null): bool
    {
        $user ??= $this->request->user();

        if (! $user instanceof User) {
            return false;
        }

        $role = strtolower(str_replace([' ', '-', '_'], '', (string) $user->role));

        return in_array($role, [
            'superadmin',
            'platformadmin',
            'supportadmin',
            'salesadmin',
            'financeadmin',
        ], true);
    }
}
