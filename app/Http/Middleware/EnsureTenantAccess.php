<?php

namespace App\Http\Middleware;

use App\Models\SchoolSetting;
use App\Support\CurrentSchool;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantAccess
{
    public function __construct(private readonly CurrentSchool $currentSchool)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $resolvedSchool = $request->attributes->get('school');

        if ($resolvedSchool && ! $this->currentSchool->isPlatformUser($user)) {
            if ((int) $user->school_id !== (int) $resolvedSchool->id) {
                abort(403, 'You do not have access to this school.');
            }

            $this->currentSchool->set($resolvedSchool);

            return $next($request);
        }

        if ($user->school_id && ! $this->currentSchool->isPlatformUser($user)) {
            $this->currentSchool->set(SchoolSetting::find($user->school_id));
        }

        return $next($request);
    }
}
