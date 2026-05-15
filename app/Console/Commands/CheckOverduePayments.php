<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Payment;
use App\Services\FCMService;
use Carbon\Carbon;

class CheckOverduePayments extends Command
{
    // الاسم الذي سنستخدمه لتشغيل الأمر يدوياً
    protected $signature = 'payments:check-overdue';
    protected $description = 'التحقق من الدفعات المتأخرة وإرسال إشعارات للمستخدمين';

    protected $fcmService;

    public function __construct(FCMService $fcmService)
    {
        parent::__construct();
        $this->fcmService = $fcmService;
    }

    public function handle()
    {
        $currentMonth = Carbon::now()->month;
        $currentYear = Carbon::now()->year;

        // 1. جلب الدفعات التي لم تدفع لشهرنا الحالي أو أشهر سابقة
        $overduePayments = Payment::where('status', '!=', 'paid')
            ->where(function($query) use ($currentMonth, $currentYear) {
                $query->where('year', '<', $currentYear)
                      ->orWhere(function($q) use ($currentMonth, $currentYear) {
                          $q->where('year', $currentYear)->where('month_number', '<', $currentMonth);
                      });
            })
            ->with('subscription.user')
            ->get();

        foreach ($overduePayments as $payment) {
            $user = $payment->subscription->user;
            
            // تحديث حالة الدفعة لمتأخرة في قاعدة البيانات
            $payment->update(['status' => 'overdue']);

            // 2. إرسال الإشعار
            
            if ($user->fcm_token) {
                $this->fcmService->sendToDevice(
                    $user->fcm_token,
                    'تذكير بالدفع - Swaida Go',
                    "عزيزي {$user->full_name}، لديك دفعة متأخرة لشهر {$payment->month_number}. يرجى السداد لتجنب تعليق الاشتراك.",
                    ['type' => 'payment_reminder', 'month' => $payment->month_number]
                );
            }
        }

        $this->info('تم فحص الدفعات وإرسال الإشعارات بنجاح.');
    }
}