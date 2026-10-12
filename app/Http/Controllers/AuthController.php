<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    // ══════════════════════════════════════════════════════════
    // الثوابت
    // ══════════════════════════════════════════════════════════
    private const LOGIN_LIMIT = 10;
    private const LOGIN_DECAY = 60;      // ثانية

    // ══════════════════════════════════════════════════════════
    // 1. تسجيل مستخدم جديد
    //    POST /register
    //    - ينشئ المستخدم
    //    - يرسل رابط توثيق البريد (signed URL عبر Laravel المدمج)
    //    - يحفظ FCM token إن أُرسل
    //    لا يُعاد token هنا — يُعاد فقط بعد توثيق البريد
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

        $user = User::create([
            'full_name' => $request->full_name,
            'email'     => $request->email,
            'phone'     => $request->phone,
            'password'  => Hash::make($request->password),
            'role'      => 'subscriber',
            'fcm_token' => $request->fcm_token,
        ]);

        // إطلاق حدث Registered → يُرسل Laravel رابط توثيق البريد تلقائياً
        event(new Registered($user));

        return response()->json([
            'message' => 'تم التسجيل بنجاح. يرجى توثيق بريدك الإلكتروني.',
        ], 201);
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
    //    عند النجاح → يُنشئ access_token ويُعيده مباشرة (دخول تلقائي)
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

        if (!$user->hasVerifiedEmail()) {
            return response()->json(['email_verified' => false], 200);
        }

        // ── البريد موثّق → دخول تلقائي ─────────────────────
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'email_verified' => true,
            'access_token'   => $token,
            'token_type'     => 'Bearer',
            'user'           => $user,
        ], 200);
    }

    // ══════════════════════════════════════════════════════════
    // 4. تسجيل الدخول
    //    POST /login
    //    يقبل: email + password
    //    يشترط: توثيق البريد فقط
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

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message'      => 'تم تسجيل الدخول بنجاح.',
            'access_token' => $token,
            'token_type'   => 'Bearer',
            'user'         => $user,
        ], 200);
    }

    // ══════════════════════════════════════════════════════════
    // 5. تحديث رمز إشعارات FCM
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
}