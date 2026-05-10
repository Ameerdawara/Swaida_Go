<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        // التحقق من أن المستخدم مسجل دخول وأن دوره 'admin'
        if ($request->user() && $request->user()->role === 'admin') {
            return $next($request);
        }

        // إذا لم يكن مديراً، نرجع خطأ 403 (غير مصرح له)
        return response()->json(['message' => 'غير مصرح لك بالوصول لهذه الصفحة.'], 403);
    }
}