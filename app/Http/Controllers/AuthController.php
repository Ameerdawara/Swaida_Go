<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Auth\Events\Registered;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
class AuthController extends Controller
{
    // ══════════════════════════════════════════════════════════
    // 1. تسجيل مستخدم جديد
    //    - ينشئ المستخدم
    //    - يرسل رابط توثيق البريد (signed URL عبر Laravel المدمج)
    //    - يولّد OTP للهاتف ويخزّنه مشفراً
    //    لا يُعاد token هنا — يُعاد فقط بعد verifyOtp()
    // ══════════════════════════════════════════════════════════
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'full_name' => 'required|string|max:255',
            'email'     => 'required|string|email|max:255|unique:users',
            'phone'     => 'required|string|unique:users',
            'password'  => 'required|string|min:8',
        ], [
            'full_name.required' => 'الاسم الكامل مطلوب.',
            'full_name.max'      => 'الاسم طويل جداً.',
            'email.required'     => 'البريد الإلكتروني مطلوب.',
            'email.email'        => 'صيغة البريد الإلكتروني غير صحيحة.',
            'email.unique'       => 'البريد الإلكتروني مستخدم مسبقاً.',
            'phone.required'     => 'رقم الهاتف مطلوب.',
            'phone.unique'       => 'رقم الهاتف مستخدم مسبقاً.',
            'password.required'  => 'كلمة المرور مطلوبة.',
            'password.min'       => 'كلمة المرور يجب أن تكون 8 أحرف على الأقل.',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // توليد رمز OTP من 6 أرقام للهاتف
        $otp = rand(100000, 999999);

        $user = User::create([
            'full_name'             => $request->full_name,
            'email'                 => $request->email,
            'phone'                 => $request->phone,
            'password'              => Hash::make($request->password),
            'phone_otp'             => Hash::make($otp),
            'phone_otp_expires_at'  => Carbon::now()->addMinutes(10),
            'role'                  => 'subscriber',
        ]);
Log::info("رمز التوثيق الجديد للمستخدم هو: " . $otp);
        // إطلاق حدث Registered → يُرسل Laravel رابط توثيق البريد تلقائياً
        // (يشترط أن يكون User يُطبّق MustVerifyEmail)
        event(new Registered($user));

        // في مرحلة الإنتاج: أرسل $otp عبر Twilio أو خدمة SMS
        // حالياً نُعيده في الـ Response لغايات الاختبار فقط
        return response()->json([
            'message'  => 'تم التسجيل بنجاح. يرجى توثيق بريدك الإلكتروني أولاً ثم رقم هاتفك.',
            'test_otp' => $otp, // ← احذف هذا السطر في الإنتاج
        ], 201);
    }

    // ══════════════════════════════════════════════════════════
    // 2. إعادة إرسال رابط توثيق البريد الإلكتروني
    //    Route: POST /auth/resend-email-verification
    //    يقبل: email
    // ══════════════════════════════════════════════════════════
    public function resendEmailVerification(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
        ], [
            'email.required' => 'البريد الإلكتروني مطلوب.',
            'email.email'    => 'صيغة البريد الإلكتروني غير صحيحة.',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json(['message' => 'المستخدم غير موجود.'], 404);
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'البريد الإلكتروني موثّق مسبقاً.'], 200);
        }

        $user->sendEmailVerificationNotification();

        return response()->json([
            'message' => 'تم إرسال رابط التوثيق مجدداً إلى بريدك الإلكتروني.',
        ], 200);
    }

    // ══════════════════════════════════════════════════════════
    // 3. فحص حالة توثيق البريد الإلكتروني (Polling)
    //    Route: GET /auth/email-verification-status
    //    يقبل: email (query param)
    //    Flutter يستدعيه كل 5 ثوانٍ للتحقق من اكتمال التوثيق
    // ══════════════════════════════════════════════════════════
    public function checkEmailVerificationStatus(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
        ], [
            'email.required' => 'البريد الإلكتروني مطلوب.',
            'email.email'    => 'صيغة البريد الإلكتروني غير صحيحة.',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json(['message' => 'المستخدم غير موجود.'], 404);
        }

        return response()->json([
            'email_verified' => $user->hasVerifiedEmail(),
        ], 200);
    }

    // ══════════════════════════════════════════════════════════
    // 4. توثيق رقم الهاتف عبر الـ OTP
    //    Route: POST /verify-otp
    //    يقبل: phone + otp
    //    شرط: يجب أن يكون البريد موثّقاً أولاً
    //    Returns: {access_token, user}
    // ══════════════════════════════════════════════════════════
    public function verifyOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string',
            'otp'   => 'required|string',
        ], [
            'phone.required' => 'رقم الهاتف مطلوب.',
            'otp.required'   => 'رمز التوثيق مطلوب.',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = User::where('phone', $request->phone)->first();

        if (!$user) {
            return response()->json(['message' => 'المستخدم غير موجود.'], 404);
        }

        // ── شرط أساسي: يجب توثيق البريد أولاً ──────────────
        if (!$user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'يرجى توثيق بريدك الإلكتروني أولاً قبل توثيق رقم الهاتف.',
            ], 403);
        }

        // التحقق من صلاحية الرمز (10 دقائق)
        if (is_null($user->phone_otp_expires_at) || Carbon::now()->isAfter($user->phone_otp_expires_at)) {
            return response()->json(['message' => 'رمز التوثيق منتهي الصلاحية.'], 400);
        }

        // التحقق من تطابق الرمز
        if (!Hash::check($request->otp, $user->phone_otp)) {
            return response()->json(['message' => 'رمز التوثيق غير صحيح.'], 400);
        }

        // توثيق الهاتف وتصفير حقول الـ OTP
        $user->phone_verified_at    = Carbon::now();
        $user->phone_otp            = null;
        $user->phone_otp_expires_at = null;
        $user->save();

        // توليد توكن الدخول (Sanctum)
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message'      => 'تم توثيق الحساب بنجاح.',
            'access_token' => $token,
            'token_type'   => 'Bearer',
            'user'         => $user,
        ], 200);
    }

    // ══════════════════════════════════════════════════════════
    // 5. إعادة إرسال OTP الهاتف
    //    Route: POST /auth/resend-otp
    //    يقبل: phone
    // ══════════════════════════════════════════════════════════
    public function resendOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string',
        ], [
            'phone.required' => 'رقم الهاتف مطلوب.',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = User::where('phone', $request->phone)->first();

        if (!$user) {
            return response()->json(['message' => 'المستخدم غير موجود.'], 404);
        }

        if ($user->phone_verified_at) {
            return response()->json(['message' => 'رقم الهاتف موثّق مسبقاً.'], 200);
        }

        // يجب توثيق البريد قبل إعادة إرسال OTP الهاتف
        if (!$user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'يرجى توثيق بريدك الإلكتروني أولاً.',
            ], 403);
        }

        $otp = rand(100000, 999999);

        $user->phone_otp            = Hash::make($otp);
        $user->phone_otp_expires_at = Carbon::now()->addMinutes(10);
        $user->save();

        // في الإنتاج: أرسل $otp عبر Twilio
        return response()->json([
            'message'  => 'تم إرسال رمز جديد إلى رقم هاتفك.',
            'test_otp' => $otp, // ← احذف في الإنتاج
        ], 200);
    }

    // ══════════════════════════════════════════════════════════
    // 6. تسجيل الدخول
    //    Route: POST /login
    //    يقبل: phone + password
    //    يشترط: توثيق البريد + توثيق الهاتف
    // ══════════════════════════════════════════════════════════
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone'    => 'required|string',
            'password' => 'required|string',
        ], [
            'phone.required'    => 'رقم الهاتف مطلوب.',
            'password.required' => 'كلمة المرور مطلوبة.',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = User::where('phone', $request->phone)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'بيانات الدخول غير صحيحة.'], 401);
        }

        if (!$user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'يرجى توثيق بريدك الإلكتروني أولاً.',
            ], 403);
        }

        if (!$user->phone_verified_at) {
            return response()->json([
                'message' => 'يرجى توثيق رقم الهاتف أولاً.',
            ], 403);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message'      => 'تم تسجيل الدخول بنجاح.',
            'access_token' => $token,
            'token_type'   => 'Bearer',
            'user'         => $user,
        ], 200);
    }

    // ══════════════════════════════════════════════════════════
    // 7. تحديث رمز إشعارات FCM
    //    Route: POST /user/fcm-token  (requires auth:sanctum)
    // ══════════════════════════════════════════════════════════
    public function updateFcmToken(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'fcm_token' => 'required|string',
        ], [
            'fcm_token.required' => 'رمز الإشعارات مطلوب.',
            'fcm_token.string'   => 'صيغة رمز الإشعارات غير صحيحة.',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user            = $request->user();
        $user->fcm_token = $request->fcm_token;
        $user->save();

        return response()->json([
            'message' => 'تم تحديث رمز الإشعارات بنجاح.',
        ], 200);
    }
}
