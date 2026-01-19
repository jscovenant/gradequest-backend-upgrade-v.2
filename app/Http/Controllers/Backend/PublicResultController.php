<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Average;
use App\Models\ResultPin;
use App\Models\AcademicSession;
use App\Models\Term;
use App\Services\ResultService;
use App\Traits\CheckFeeStatus;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PublicResultController extends Controller
{
    use CheckFeeStatus;

    /**
     * Check student's result using registration number and PIN
     */
    public function checkWithPin(Request $request)
    {
        $request->validate([
            'reg_no' => 'required|string',
            'pin'    => 'required|string',
        ]);

        /**
         * 1️⃣ FIND STUDENT (UNIQUE REG NO — CAN SPAN MANY SESSIONS)
         */
        $student = User::where('reg_no', $request->reg_no)
            ->where('role', 'student')
            ->first();

        if (!$student) {
            return response()->json([
                'message' => 'Student not found'
            ], 404);
        }

        /**
         * 2️⃣ FETCH CURRENT SESSION & ACTIVE TERM (SCHOOL SCOPE)
         */
        $currentSession = AcademicSession::where('is_current', true)
            ->where('school_id', $student->school_id)
            ->first();

        $currentTerm = Term::where('status', 'Active')
            ->where('school_id', $student->school_id)
            ->first();

        if (!$currentSession || !$currentTerm) {
            return response()->json([
                'message' => 'Current academic session or active term not configured'
            ], 500);
        }

        /**
         * 3️⃣ VALIDATE PIN (MUST BELONG TO CURRENT TERM & SESSION)
         */
        $pin = ResultPin::where('pin', $request->pin)
            ->where('is_active', true)
            ->where('term', $currentTerm->name)
            ->where('session', $currentSession->name)
            ->first();

        if (!$pin) {
            return response()->json([
                'message' => 'Invalid result PIN for the current term and academic session'
            ], 403);
        }

        /**
         * 4️⃣ PIN EXPIRY CHECK
         */
        if ($pin->expires_at && now()->gt($pin->expires_at)) {
            return response()->json([
                'message' => 'Result PIN has expired'
            ], 403);
        }

        /**
         * 5️⃣ PIN USAGE LIMIT CHECK
         */
        if ($pin->used_count >= $pin->max_uses) {
            return response()->json([
                'message' => 'Result PIN usage exceeded'
            ], 403);
        }

        /**
         * 6️⃣ FEE RESTRICTION CHECK
         */
        try {
            $this->restrictIfUnpaid($student);
        } catch (HttpException $e) {
            return response()->json([
                'status'  => 'fee_restricted',
                'message' => $e->getMessage(),
            ], 403);
        }

        /**
         * 7️⃣ RESULT CONTEXT (STRICTLY CURRENT TERM & SESSION)
         */
        $term    = $currentTerm->name;
        $session = $currentSession->name;
        $classId = $student->level_id;

        /**
         * 8️⃣ CONFIRM RESULT EXISTS FOR CURRENT CONTEXT
         */
        $exists = Average::where([
            'user_id'  => $student->id,
            'class_id' => $classId,
            'term'     => $term,
            'session'  => $session,
        ])->exists();

        if (!$exists) {
            return response()->json([
                'message' => "Result not available for {$term}, {$session}"
            ], 404);
        }

        /**
         * 9️⃣ INCREMENT PIN USAGE
         */
        $pin->increment('used_count');

        /**
         * 🔟 BUILD & RETURN RESULT
         */
        return app(ResultService::class)->build(
            user: $student,
            classId: $classId,
            term: $term,
            session: $session
        );
    }
}
