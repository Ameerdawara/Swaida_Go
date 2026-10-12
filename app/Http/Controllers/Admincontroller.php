<?php

namespace App\Http\Controllers;

use App\Models\AppFund;
use App\Models\CharityFund;
use App\Models\Payment;
use App\Models\User;
use Carbon\Carbon;

class AdminController extends Controller
{
    // ══════════════════════════════════════════════════════════
    // GET /api/admin/dashboard-stats
    // يُعيد: total_subscribers, app_fund_balance,
    //         charity_fund_balance, this_month_payments
    // ══════════════════════════════════════════════════════════
    public function dashboardStats()
    {
        $currentMonth = Carbon::now()->month;
        $currentYear  = Carbon::now()->year;

        // عدد المشتركين (role = subscriber)
        $totalSubscribers = User::where('role', 'subscriber')->count();

        // رصيد صندوق التطبيق
        $appFund     = AppFund::first();
        $appBalance  = $appFund ? (float) $appFund->balance : 0.0;

        // رصيد صندوق الجمعية
        $charityFund     = CharityFund::first();
        $charityBalance  = $charityFund ? (float) $charityFund->balance : 0.0;

        // إجمالي المدفوعات هذا الشهر (paid_at في الشهر والسنة الحاليين)
        $thisMonthPayments = Payment::whereNotNull('paid_at')
            ->whereMonth('paid_at', $currentMonth)
            ->whereYear('paid_at', $currentYear)
            ->sum('amount');

        return response()->json([
            'total_subscribers'    => $totalSubscribers,
            'app_fund_balance'     => $appBalance,
            'charity_fund_balance' => $charityBalance,
            'this_month_payments'  => (float) $thisMonthPayments,
        ], 200);
    }

    // ══════════════════════════════════════════════════════════
    // GET /api/admin/subscribers-report?month=&year=
    // يُعيد قائمة المشتركين مع حالة الأشهر الـ 12
    // ══════════════════════════════════════════════════════════
    public function subscribersReport(\Illuminate\Http\Request $request)
    {
        $month = (int) ($request->query('month', Carbon::now()->month));
        $year  = (int) ($request->query('year',  Carbon::now()->year));

        $subscribers = User::where('role', 'subscriber')
            ->with(['subscription.payments' => function ($q) use ($year) {
                $q->where('year', $year);
            }])
            ->get();

        $data = $subscribers->map(function (User $user) use ($month, $year) {
            $subscription = $user->subscription;

            // المشترك بدون اشتراك نشط — نُعيد صفوف فارغة
            if (!$subscription) {
                return [
                    'id'            => $user->id,
                    'full_name'     => $user->full_name,
                    'phone'         => $user->phone,
                    'annual_amount' => 0,
                    'monthly_amount'=> 0,
                    'months'        => array_fill(0, 12, 'pending'),
                ];
            }

            // بناء مصفوفة الحالات لـ 12 شهراً
            $payments = $subscription->payments->keyBy('month_number');
            $months   = [];

            for ($m = 1; $m <= 12; $m++) {
                $payment  = $payments->get($m);
                $months[] = $payment
                    ? Payment::getStatus($payment->month_number, $payment->year, $payment->paid_at)
                    : 'pending';
            }

            return [
                'id'             => $user->id,
                'full_name'      => $user->full_name,
                'phone'          => $user->phone,
                'annual_amount'  => (float) $subscription->annual_amount,
                'monthly_amount' => (float) $subscription->monthly_amount,
                'months'         => $months, // مصفوفة 12 عنصر: 'paid'|'overdue'|'pending'
            ];
        });

        return response()->json(['data' => $data], 200);
    }

    // ══════════════════════════════════════════════════════════
    // GET /api/admin/fund-transfers?type=&month=&year=
    // يُعيد سجل التحويلات مع فلترة اختيارية
    // type: 'app' | 'charity' (غير مستخدم حالياً — جاهز للتوسعة)
    // ══════════════════════════════════════════════════════════
    public function fundTransfers(\Illuminate\Http\Request $request)
    {
        $month = $request->query('month');
        $year  = $request->query('year');

        $query = \App\Models\FundTransfer::with('admin:id,full_name')
            ->orderBy('transfer_date', 'desc');

        if ($month && $year) {
            $query->whereMonth('transfer_date', (int) $month)
                  ->whereYear('transfer_date',  (int) $year);
        } elseif ($year) {
            $query->whereYear('transfer_date', (int) $year);
        }

        $transfers = $query->get()->map(fn ($t) => [
            'id'             => $t->id,
            'amount'         => (float) $t->amount,
            'recipient_name' => $t->recipient_name,
            'receipt_number' => $t->receipt_number,
            'transfer_date'  => $t->transfer_date,
            'note'           => $t->note ?? '',
        ]);

        return response()->json(['data' => $transfers], 200);
    }

    // ══════════════════════════════════════════════════════════
    // GET /api/admin/fund-summary?type=&month=&year=
    // يُعيد ملخص الصناديق للشهر المحدد
    // ══════════════════════════════════════════════════════════
    public function fundSummary(\Illuminate\Http\Request $request)
    {
        $month = (int) ($request->query('month', Carbon::now()->month));
        $year  = (int) ($request->query('year',  Carbon::now()->year));

        // إجمالي المدفوعات المستلمة هذا الشهر
        $totalReceivedThisMonth = Payment::whereNotNull('paid_at')
            ->whereMonth('paid_at', $month)
            ->whereYear('paid_at',  $year)
            ->sum('amount');

        // إجمالي التحويلات هذا الشهر
        $totalTransferredThisMonth = \App\Models\FundTransfer::whereMonth('transfer_date', $month)
            ->whereYear('transfer_date', $year)
            ->sum('amount');

        $appFund    = AppFund::first();
        $charityFund = CharityFund::first();

        return response()->json([
            'total_received_this_month'    => (float) $totalReceivedThisMonth,
            'total_transferred_this_month' => (float) $totalTransferredThisMonth,
            'app_fund_balance'             => $appFund    ? (float) $appFund->balance    : 0.0,
            'charity_fund_balance'         => $charityFund ? (float) $charityFund->balance : 0.0,
        ], 200);
    }

    // ══════════════════════════════════════════════════════════
    // POST /api/admin/fund-transfers
    // نقل من AdminController لأن FundTransferController موجود
    // — نُبقيه هناك ونضيف فقط المسارات الناقصة هنا
    // ══════════════════════════════════════════════════════════
}
