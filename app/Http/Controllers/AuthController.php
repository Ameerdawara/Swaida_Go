<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class AuthController extends Controller
{
    // 1. تسجيل مستخدم جديد
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'full_name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'phone' => 'required|string|unique:users',
            'password' => 'required|string|min:8',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // توليد رمز OTP من 6 أرقام
        $otp = rand(100000, 999999);

        $user = User::create([
            'full_name' => $request->full_name,
            'email' => $request->email,
            'phone' => $request->phone,
            'password' => Hash::make($request->password),
            'phone_otp' => Hash::make($otp), // تشفير الرمز لحماية قاعدة البيانات
            'phone_otp_expires_at' => Carbon::now()->addMinutes(10), // صلاحية 10 دقائق
            'role' => 'subscriber',
        ]);

        // هنا لاحقاً سنقوم باستدعاء خدمة إرسال الرسائل (مثل Twilio أو خدمة محلية) لإرسال $otp للمستخدم
        // حالياً سنقوم بإرجاعه في الـ Response لغايات الفحص (Testing)

        return response()->json([
            'message' => 'تم إنشاء الحساب بنجاح. يرجى توثيق رقم الهاتف.',
            'test_otp' => $otp // احذف هذا السطر في مرحلة الإنتاج (Production)
        ], 201);
    }

    // 2. توثيق رقم الهاتف عبر الـ OTP
    public function verifyOtp(Request $request)
    {
        $request->validate([
            'phone' => 'required|string',
            'otp' => 'required|string',
        ]);

        $user = User::where('phone', $request->phone)->first();

        if (!$user) {
            return response()->json(['message' => 'المستخدم غير موجود'], 404);
        }

        // التحقق من صلاحية الرمز
        if (Carbon::now()->isAfter($user->phone_otp_expires_at)) {
            return response()->json(['message' => 'رمز التوثيق منتهي الصلاحية'], 400);
        }

        // التحقق من تطابق الرمز
        if (!Hash::check($request->otp, $user->phone_otp)) {
            return response()->json(['message' => 'رمز التوثيق غير صحيح'], 400);
        }

        // توثيق الحساب وتصفير حقول الـ OTP
        $user->phone_verified_at = Carbon::now();
        $user->phone_otp = null;
        $user->phone_otp_expires_at = null;
        $user->save();

        // توليد توكن الدخول
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'تم توثيق الحساب بنجاح',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $user
        ], 200);
    }

    // 3. تسجيل الدخول
    public function login(Request $request)
    {
        $request->validate([
            'phone' => 'required|string',
            'password' => 'required|string',
        ]);

        $user = User::where('phone', $request->phone)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'بيانات الدخول غير صحيحة'], 401);
        }

        if (!$user->phone_verified_at) {
            return response()->json(['message' => 'يرجى توثيق رقم الهاتف أولاً'], 403);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'تم تسجيل الدخول بنجاح',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $user
        ], 200);
    }
}