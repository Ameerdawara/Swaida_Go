<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;

class NotificationController extends Controller
{
    // جلب كافة الإشعارات للمستخدم الحالي
    public function index(Request $request)
    {
        $notifications = $request->user()->notifications; // يجلب المقروء وغير المقروء
        
        return response()->json($notifications);
    }

    // جلب الإشعارات غير المقروءة فقط (مفيد لعرض رقم التنبيهات في التطبيق)
    public function unread(Request $request)
    {
        return response()->json($request->user()->unreadNotifications);
    }

    // تحديد إشعار معين كـ "تمت القراءة"
    public function markAsRead(Request $request, $id)
    {
        $notification = $request->user()->notifications()->where('id', $id)->first();
        
        if ($notification) {
            $notification->markAsRead();
            return response()->json(['message' => 'تم تحديث حالة الإشعار']);
        }

        return response()->json(['message' => 'الإشعار غير موجود'], 404);
    }

    // تحديد كل الإشعارات كـ "مقروءة" (زر مسح الكل)
    public function markAllAsRead(Request $request)
    {
        $request->user()->unreadNotifications->markAsRead();
        return response()->json(['message' => 'تم تحديد الكل كـ مقروء']);
    }
}
