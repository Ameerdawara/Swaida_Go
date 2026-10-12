<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail; // ← تفعيل الواجهة
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;             // ← مطلوب لـ Sanctum

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasApiTokens ;
    protected $fillable = [
        'full_name',
        'email',
        'phone',
        'password',
        'phone_otp',
        'phone_otp_expires_at',
        'role',
        'fcm_token',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'phone_otp',          // لا نكشف الـ OTP المشفّر في الـ Response
        'phone_otp_expires_at',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at'     => 'datetime',
            'phone_verified_at'     => 'datetime',
            'phone_otp_expires_at'  => 'datetime',
        ];
    }

    // ── Relationships ──────────────────────────────────────

    public function subscription()
    {
        return $this->hasOne(Subscription::class);
    }

    public function fundTransfers()
    {
        return $this->hasMany(FundTransfer::class, 'created_by');
    }
}
