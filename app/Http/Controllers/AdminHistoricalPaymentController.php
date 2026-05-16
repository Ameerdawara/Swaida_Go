<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use App\Models\AppFund;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AdminHistoricalPaymentController extends Controller
{
    // ══════════════════════════════════════════════════════════
    // GET /api/admin/subscribers-list
    // قائمة مبسّطة بالمشتركين (id, full_name, phone, annual_amount)
    // تُستخدم في صفحة تسجيل المدفوعات التاريخية
    // ══════════════════════════════════════════════════════════
    public function subscribersList()
    {
        $subscribers = User::where('role', 'subscriber')
            ->with('subscription')
            ->get()
            ->map(function (User $user) {
                $sub = $user->subscription;
                return [
                    'id'              => $user->id,
                    'full_name'       => $user->full_name,
                    'phone'           => $user->phone,
                    'subscription_id' => $sub?->id,
                    'annual_amount'   => $sub ? (float) $sub->annual_amount  : 0.0,
                    'monthly_amount'  => $sub ? (float) $sub->monthly_amount : 0.0,
                    'has_subscription'=> $sub !== null,
                ];
            });

        return response()->json(['data' => $subscribers], 200);
    }

    // ══════════════════════════════════════════════════════════
    // GET /api/admin/subscriber-payments/{userId}?year=
    // يُعيد حالة الدفعات لمشترك معين مقسّمة حسب السنة
    // ══════════════════════════════════════════════════════════
    public function subscriberPayments(Request $request, int $userId)
    {
        $year = (int) ($request->query('year', Carbon::now()->year));

        $user = User::with(['subscription.payments' => function ($q) use ($year) {
            $q->where('year', $year);
        }])->findOrFail($userId);

        $subscription = $user->subscription;

        if (!$subscription) {
            return response()->json(['message' => 'لا يوجد اشتراك لهذا المشترك'], 404);
        }

        $payments = $subscription->payments->keyBy('month_number');
        $months   = [];

        for ($m = 1; $m <= 12; $m++) {
            $payment  = $payments->get($m);
            $months[] = [
                'month_number' => $m,
                'payment_id'   => $payment?->id,
                'status'       => $payment
                    ? Payment::getStatus($payment->month_number, $payment->year, $payment->paid_at)
                    : 'no_record', // لا يوجد سجل دفع لهذا الشهر بعد
                'paid_at'      => $payment?->paid_at,
                'amount'       => $payment ? (float) $payment->amount : (float) $subscription->monthly_amount,
            ];
        }

        return response()->json([
            'user' => [
                'id'        => $user->id,
                'full_name' => $user->full_name,
                'phone'     => $user->phone,
            ],
            'subscription' => [
                'id'             => $subscription->id,
                'annual_amount'  => (float) $subscription->annual_amount,
                'monthly_amount' => (float) $subscription->monthly_amount,
            ],
            'year'   => $year,
            'months' => $months,
        ], 200);
    }

    // ══════════════════════════════════════════════════════════
    // POST /api/admin/mark-historical-paid
    // يُسجّل دفعات تاريخية (قبل إطلاق التطبيق) كمدفوعة
    //
    // Body:
    // {
    //   "user_id": 5,
    //   "entries": [
    //     { "year": 2023, "months": [1,2,3,4,5,6,7,8,9,10,11,12] },
    //     { "year": 2024, "months": [1,2,3,4] }
    //   ],
    //   "note": "مشترك قبل إطلاق التطبيق – تم التسجيل يدوياً"
    // }
    // ══════════════════════════════════════════════════════════
    public function markHistoricalPaid(Request $request)
    {
        $request->validate([
            'user_id'          => 'required|exists:users,id',
            'entries'          => 'required|array|min:1',
            'custom_amount' => 'nullable|numeric',
            'entries.*.year'   => 'required|integer|min:2000|max:2100',
            'entries.*.months' => 'required|array|min:1',
            'entries.*.months.*' => 'integer|min:1|max:12',
            'note'             => 'nullable|string|max:500',
        ], [
            'user_id.required'           => 'يجب تحديد المشترك.',
            'user_id.exists'             => 'المشترك غير موجود.',
            'entries.required'           => 'يجب تحديد الأشهر المراد تسجيلها.',
            'entries.*.year.required'    => 'يجب تحديد السنة.',
            'entries.*.months.required'  => 'يجب تحديد شهر واحد على الأقل.',
        ]);

        $user         = User::findOrFail($request->user_id);
$subscription = Subscription::where('user_id', $user->id)->first();
        if (!$subscription) {
            return response()->json(['message' => 'لا يوجد اشتراك لهذا المشترك'], 404);
        }
$monthlyAmountToSave = $request->custom_amount ?? ($subscription ? $subscription->monthly_amount : 0);
        DB::beginTransaction();
        try {
            $markedCount  = 0;
            $createdCount = 0;
            $totalAmount  = 0.0;
            $paidAt       = Carbon::now();

            foreach ($request->entries as $entry) {
                $year   = (int) $entry['year'];
                $months = array_unique((array) $entry['months']);

                foreach ($months as $monthNumber) {
                    $monthNumber = (int) $monthNumber;

                    // هل يوجد سجل دفع لهذا الشهر؟
                    $payment = Payment::where('subscription_id', $subscription->id)
                        ->where('year', $year)
                        ->where('month_number', $monthNumber)
                        ->first();

                    if ($payment) {
                        // إذا كان مدفوعاً بالفعل، نتجاهله
                        if ($payment->paid_at !== null) continue;

                        $payment->status  = 'paid';
                        $payment->paid_at = $paidAt;
                        $payment->note    = $request->note ?? 'تسجيل يدوي بواسطة المدير';
                        $payment->save();
                        $markedCount++;
                        $totalAmount += (float) $payment->amount;
                    } else {
                        // إنشاء سجل دفع جديد للسنوات/الأشهر التي لم تُنشأ بعد
                        Payment::create([
                            'subscription_id'      => $subscription->id,
                            'month_number'         => $monthNumber,
                            'year'                 => $year,
                            'amount'               => $monthlyAmountToSave,
                            'status'               => 'paid',
                            'paid_at'              => $paidAt,
                            'note'                 => $request->note ?? 'تسجيل يدوي بواسطة المدير',
                        ]);
                        $createdCount++;
                        $totalAmount += (float) $monthlyAmountToSave;
                    }
                }
            }

            // تحديث رصيد صندوق التطبيق
            if ($totalAmount > 0) {
                $appFund = AppFund::firstOrCreate(['id' => 1]);
                $appFund->balance += $totalAmount;
                $appFund->last_updated = Carbon::now();
                $appFund->save();
            }

            DB::commit();

            return response()->json([
                'message'       => 'تم تسجيل المدفوعات التاريخية بنجاح',
                'marked_count'  => $markedCount,
                'created_count' => $createdCount,
                'total_entries' => $markedCount + $createdCount,
                'total_amount'  => round($totalAmount, 2),
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'فشل تسجيل المدفوعات',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}
