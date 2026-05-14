<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Auth\Events\Registered;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    // ══════════════════════════════════════════════════════════
    // الثوابت
    // ══════════════════════════════════════════════════════════
    private const OTP_EXPIRY_MINUTES  = 10;
    private const OTP_RESEND_LIMIT    = 5;       // محاولات قبل الحظر
    private const OTP_RESEND_DECAY    = 60;      // ثانية
    private const LOGIN_LIMIT         = 10;
    private const LOGIN_DECAY         = 60;      // ثانية

    // ══════════════════════════════════════════════════════════
    // 1. تسجيل مستخدم جديد
    //    POST /register
    //    - ينشئ المستخدم
    //    - يرسل رابط توثيق البريد (signed URL عبر Laravel المدمج)
    //    - يولّد OTP للهاتف ويخزّنه مشفراً
    //    - يحفظ FCM token إن أُرسل
    //    لا يُعاد token هنا — يُعاد فقط بعد verifyOtp()
    // ══════════════════════════════════════════════════════════
    public function register(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'full_name' => 'required|string|max:255',
            'email'     => 'required|string|email|max:255|unique:users',
            'phone'     => 'required|string|unique:users',
            'password'  => 'required|string|min:8',
            'fcm_token' => 'nullable|string',
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

        $otp = $this->generateOtp();

        $user = User::create([
            'full_name'            => $request->full_name,
            'email'                => $request->email,
            'phone'                => $request->phone,
            'password'             => Hash::make($request->password),
            'phone_otp'            => Hash::make($otp),
            'phone_otp_expires_at' => Carbon::now()->addMinutes(self::OTP_EXPIRY_MINUTES),
            'role'                 => 'subscriber',
            'fcm_token'            => $request->fcm_token,
        ]);

        Log::info("OTP للمستخدم الجديد [{$user->email}]: {$otp}");

        // إطلاق حدث Registered → يُرسل Laravel رابط توثيق البريد تلقائياً
        event(new Registered($user));

        // في الإنتاج: أرسل $otp عبر Twilio أو خدمة SMS
        $response = ['message' => 'تم التسجيل بنجاح. يرجى توثيق بريدك الإلكتروني أولاً ثم رقم هاتفك.'];

        if (app()->environment('local')) {
            $response['test_otp'] = $otp;
        }

        return response()->json($response, 201);
    }

    // ══════════════════════════════════════════════════════════
    // 2. إعادة إرسال رابط توثيق البريد الإلكتروني
    //    POST /auth/resend-email-verification
    //    يقبل: email
    // ══════════════════════════════════════════════════════════
    public function resendEmailVerification(Request $request): JsonResponse
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
            // نُعيد 200 عمداً لمنع كشف وجود البريد (Security: User Enumeration)
            return response()->json(['message' => 'إذا كان البريد مسجلاً، سيصلك رابط التوثيق.'], 200);
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'البريد الإلكتروني موثّق مسبقاً.'], 200);
        }

        $user->sendEmailVerificationNotification();

        return response()->json(['message' => 'تم إرسال رابط التوثيق مجدداً إلى بريدك الإلكتروني.'], 200);
    }

    // ══════════════════════════════════════════════════════════
    // 3. فحص حالة توثيق البريد الإلكتروني (Polling)
    //    GET /auth/email-verification-status?email=...
    //    Flutter يستدعيه كل 5 ثوانٍ للتحقق من اكتمال التوثيق
    // ══════════════════════════════════════════════════════════
    public function checkEmailVerificationStatus(Request $request): JsonResponse
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

        return response()->json(['email_verified' => $user->hasVerifiedEmail()], 200);
    }

    // ══════════════════════════════════════════════════════════
    // 4. توثيق رقم الهاتف عبر الـ OTP
    //    POST /verify-otp
    //    يقبل: phone + otp
    //    شرط: يجب أن يكون البريد موثّقاً أولاً
    //    Returns: {access_token, user}
    // ══════════════════════════════════════════════════════════
    public function verifyOtp(Request $request): JsonResponse
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
    //    POST /auth/resend-otp
    //    يقبل: phone
    // ══════════════════════════════════════════════════════════
    public function resendOtp(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string',
        ], [
            'phone.required' => 'رقم الهاتف مطلوب.',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Rate limiting: منع الإفراط في طلب OTP
        $rateLimitKey = 'resend-otp:' . $request->phone;
        if (RateLimiter::tooManyAttempts($rateLimitKey, self::OTP_RESEND_LIMIT)) {
            $seconds = RateLimiter::availableIn($rateLimitKey);
            return response()->json([
                'message' => "طلبات كثيرة جداً. حاول بعد {$seconds} ثانية.",
            ], 429);
        }
        RateLimiter::hit($rateLimitKey, self::OTP_RESEND_DECAY);

        $user = User::where('phone', $request->phone)->first();

        if (!$user) {
            return response()->json(['message' => 'المستخدم غير موجود.'], 404);
        }

        if ($user->phone_verified_at) {
            return response()->json(['message' => 'رقم الهاتف موثّق مسبقاً.'], 200);
        }

        if (!$user->hasVerifiedEmail()) {
            return response()->json(['message' => 'يرجى توثيق بريدك الإلكتروني أولاً.'], 403);
        }

        $otp = $this->generateOtp();

        $user->phone_otp            = Hash::make($otp);
        $user->phone_otp_expires_at = Carbon::now()->addMinutes(self::OTP_EXPIRY_MINUTES);
        $user->save();

        Log::info("OTP جديد للمستخدم [{$user->phone}]: {$otp}");

        // في الإنتاج: أرسل $otp عبر Twilio
        $response = ['message' => 'تم إرسال رمز جديد إلى رقم هاتفك.'];

        if (app()->environment('local')) {
            $response['test_otp'] = $otp;
        }

        return response()->json($response, 200);
    }

    // ══════════════════════════════════════════════════════════
    // 6. تسجيل الدخول
    //    POST /login
    //    يقبل: email + password
    //    يشترط: توثيق البريد + توثيق الهاتف
    // ══════════════════════════════════════════════════════════
    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email'    => 'required|string|email',
            'password' => 'required|string',
        ], [
            'email.required'    => 'البريد الإلكتروني مطلوب.',
            'email.email'       => 'صيغة البريد الإلكتروني غير صحيحة.',
            'password.required' => 'كلمة المرور مطلوبة.',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Rate limiting لمنع Brute Force
        $rateLimitKey = 'login:' . Str::lower($request->email) . '|' . $request->ip();
        if (RateLimiter::tooManyAttempts($rateLimitKey, self::LOGIN_LIMIT)) {
            $seconds = RateLimiter::availableIn($rateLimitKey);
            return response()->json([
                'message' => "محاولات كثيرة جداً. حاول بعد {$seconds} ثانية.",
            ], 429);
        }

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            RateLimiter::hit($rateLimitKey, self::LOGIN_DECAY);
            return response()->json(['message' => 'بيانات الدخول غير صحيحة.'], 401);
        }

        // نجح التحقق — نصفّر العداد
        RateLimiter::clear($rateLimitKey);

        if (!$user->hasVerifiedEmail()) {
            return response()->json(['message' => 'يرجى توثيق بريدك الإلكتروني أولاً.'], 403);
        }

        if (!$user->phone_verified_at) {
            return response()->json(['message' => 'يرجى توثيق رقم الهاتف أولاً.'], 403);
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
    //    POST /update-fcm-token  (requires auth:sanctum)
    // ══════════════════════════════════════════════════════════
    public function updateFcmToken(Request $request): JsonResponse
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

        return response()->json(['message' => 'تم تحديث رمز الإشعارات بنجاح.'], 200);
    }

    // ══════════════════════════════════════════════════════════
    // HELPERS
    // ══════════════════════════════════════════════════════════

    /**
     * توليد رمز OTP عشوائي من 6 أرقام باستخدام random_int الآمن
     */
    private function generateOtp(): int
    {
        return random_int(100000, 999999);
    }
}