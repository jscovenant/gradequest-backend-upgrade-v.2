<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Average;
use Illuminate\Support\Facades\DB;

class StatisticsController extends Controller
{


    public function getStudentPassFailData($session)
    {
        $data = Average::select(DB::raw('class,
                           SUM(CASE WHEN total_average >= 50 THEN 1 ELSE 0 END) as passed,
                           SUM(CASE WHEN total_average < 50 THEN 1 ELSE 0 END) as failed'))
            ->where('session', $session)
            ->groupBy('class')
            ->get();

        return response()->json($data);
    }

    public function getSessions()
    {
        $sessions = Average::select('session')
            ->distinct()
            ->orderBy('session', 'desc') // Fetch latest sessions
            ->limit(5)
            ->pluck('session');

        return response()->json($sessions);
    }
}
