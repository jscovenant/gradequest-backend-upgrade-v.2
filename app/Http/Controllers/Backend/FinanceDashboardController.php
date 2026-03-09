<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\FinancialRecord;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\IncomeReportExport;
use PDF;

class FinanceDashboardController extends Controller
{
   public function index(Request $request)
{
    $schoolId = Auth::user()->school_id;

    $startDate = $request->query('start_date');
    $endDate = $request->query('end_date');
    $type = $request->query('type');
    $categoryId = $request->query('category_id');

    $query = FinancialRecord::where('school_id', $schoolId);

    if ($startDate && $endDate) {
        $query->whereBetween('date', [$startDate, $endDate]);
    }

    if ($type && in_array($type, ['income', 'expense'])) {
        $query->where('type', $type);
    }

    if ($categoryId) {
        $query->where('category_id', $categoryId);
    }

    $records = $query->with('category')->get();

    /* ================= KPI ================= */

    $totalIncome = $records->where('type', 'income')->sum('amount');
    $totalExpense = $records->where('type', 'expense')->sum('amount');

    $netProfit = $totalIncome - $totalExpense;

    $profitMargin = $totalIncome > 0
        ? ($netProfit / $totalIncome) * 100
        : 0;

    $salaryExpense = $records
        ->filter(fn($r) => $r->category?->name === 'Salaries')
        ->sum('amount');

    $salaryBurden = $totalIncome > 0
        ? ($salaryExpense / $totalIncome) * 100
        : 0;

    /* ================= MONTHLY TREND (FILTERED) ================= */

    $monthly = (clone $query)
        ->selectRaw("
            DATE_FORMAT(date, '%Y-%m') as month,
            SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) as income,
            SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) as expense
        ")
        ->groupBy('month')
        ->orderBy('month')
        ->get();

    return response()->json([
        'kpi' => [
            'total_income' => $totalIncome,
            'total_expense' => $totalExpense,
            'net_profit' => $netProfit,
            'profit_margin' => round($profitMargin, 2),
            'salary_burden' => round($salaryBurden, 2),
        ],
        'monthly_trend' => $monthly,
        'records' => $records,
    ]);    
}


public function generateIncomeReport(Request $request)
{
    $schoolId = auth::id();

    $query = FinancialRecord::where('school_id', $schoolId)
        ->where('type', 'income');

    if ($request->start_date) {
        $query->whereDate('date', '>=', $request->start_date);
    }

    if ($request->end_date) {
        $query->whereDate('date', '<=', $request->end_date);
    }

    if ($request->category_id) {
        $query->where('category_id', $request->category_id);
    }

    $records = $query->get();

    $totalIncome = $records->sum('amount');
    $transactionCount = $records->count();
    $averageIncome = $transactionCount > 0
        ? $totalIncome / $transactionCount
        : 0;

    return response()->json([
        'total_income' => $totalIncome,
        'transaction_count' => $transactionCount,
        'average_income' => round($averageIncome, 2),
    ]);
}




}
