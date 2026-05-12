<?php

namespace App\Services;

use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;
use Illuminate\Support\Facades\Log;

class FCMService
{
    protected $messaging;

    // حقن الاعتمادية (Dependency Injection) لمكتبة فايربيس
    public function __construct(Messaging $messaging)
    {
        $this->messaging = $messaging;
    }

    /**
     * إرسال إشعار لجهاز واحد
     * * @param string $deviceToken (FCM Token الخاص بالمستخدم)
     * @param string $title (عنوان الإشعار)
     * @param string $body (محتوى الإشعار)
     * @param array $data (بيانات إضافية خفية يمكن إرسالها للتطبيق)
     */
    public function sendToDevice($deviceToken, $title, $body, $data = [])
    {
        // إذا لم يكن المستخدم يملك توكن، نتجاهل الإرسال
        if (empty($deviceToken)) {
            return false;
        }

        try {
            // تجهيز الإشعار المرئي (Title & Body)
            $notification = Notification::create($title, $body);
            
            // ربط الإشعار بالـ Token وإضافة أي بيانات إضافية
            $message = CloudMessage::withTarget('token', $deviceToken)
                ->withNotification($notification)
                ->withData($data);

            // إرسال الإشعار
            $this->messaging->send($message);
            
            return true;

        } catch (\Exception $e) {
            // تسجيل الخطأ في ملفات الـ Log في حال فشل الإرسال (مهم جداً للـ Debugging)
            Log::error('FCM Send Error: ' . $e->getMessage());
            return false;
        }
    }
}