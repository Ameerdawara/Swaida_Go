<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    protected $fillable = [
    'user_id', 'annual_amount', 'monthly_amount', 'start_date', 'status'
];

// ينتمي لمستخدم
public function user()
{
    return $this->belongsTo(User::class);
}

// لديه العديد من الدفعات (الـ 12 شهراً)
public function payments()
{
    return $this->hasMany(Payment::class);
}
}
