<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    // جلب نسبة العمولة الحالية (لعرضها في التطبيق)
    public function getCommission()
    {
        // نجلب أول صف، أو نرجع صفر إذا لم يتم تعيينها بعد
        $setting = Setting::first();
        
        return response()->json([
            'commission_percentage' => $setting ? (float) $setting->commission_percentage : 0
        ], 200);
    }

    // تحديث نسبة العمولة (خاص بالمدير)
    public function updateCommission(Request $request)
    {
        // التحقق من المدخلات مع رسائل باللغة العربية
        $request->validate([
            'commission_percentage' => 'required|numeric|min:0|max:100',
        ], [
            'commission_percentage.required' => 'حقل نسبة العمولة مطلوب.',
            'commission_percentage.numeric' => 'يجب أن تكون نسبة العمولة عبارة عن رقم.',
            'commission_percentage.min' => 'نسبة العمولة لا يمكن أن تكون أقل من 0.',
            'commission_percentage.max' => 'نسبة العمولة لا يمكن أن تتجاوز 100.',
        ]);

        $setting = Setting::firstOrCreate(['id' => 1]);
        $setting->commission_percentage = $request->commission_percentage;
        $setting->save();

        return response()->json([
            'message' => 'تم تحديث نسبة العمولة بنجاح',
            'commission_percentage' => (float) $setting->commission_percentage
        ], 200);
    }
}