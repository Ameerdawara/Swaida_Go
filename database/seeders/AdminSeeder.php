<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;

class AdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::create([
            'full_name' => 'Anas Admin',             // اسم المدير
            'email' => 'admin@swaida-go.com',         // البريد الإلكتروني للدخول
            'phone' => '0930000000',                  // رقم الهاتف
            'password' => Hash::make('Anas@2026'),   // كلمة المرور (تأكد من تغييرها لاحقاً)
            'role' => 'admin',                        // الصلاحية: مدير
            'phone_verified_at' => Carbon::now(),     // جعله موثقاً فوراً
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }
}