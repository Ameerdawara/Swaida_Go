<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Payment;
use App\Services\FCMService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB; 
use Illuminate\Support\Str;
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

        // 1. جلب الدفعات المتأخرة مع بيانات المستخدم
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
            
            if (!$user) continue; // تخطي إذا لم يوجد مستخدم

            // تحديث حالة الدفعة
            $payment->update(['status' => 'overdue']);

            $title = 'تذكير بالدفع - Swaida Go';
            $message = "عزيزي {$user->full_name}، لديك دفعة متأخرة لشهر {$payment->month_number}. يرجى السداد لتجنب تعليق الاشتراك.";

            // 2. الحفظ في جدول الإشعارات (ليظهر داخل التطبيق لاحقاً)
            \Illuminate\Support\Facades\DB::table('notifications')->insert([
                'id' => \Illuminate\Support\Str::uuid(),
                'type' => 'App\Notifications\PaymentReminder',
                'notifiable_type' => 'App\Models\User',
                'notifiable_id' => $user->id, // تم التصحيح من $admin إلى $user
                'data' => json_encode([
                    'title' => $title,
                    'body' => $message,
                    'payment_id' => $payment->id,
                    'type' => 'overdue_reminder'
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 3. الإرسال عبر FCM (التنبيه المنبثق)
            if ($user->fcm_token) {
                $this->fcmService->sendToDevice(
                    $user->fcm_token,
                    $title,
                    $message,
                    ['type' => 'payment_reminder', 'month' => (string)$payment->month_number]
                );
            }
        }

        $this->info('تم فحص الدفعات وإرسال الإشعارات بنجاح.');
    }
}