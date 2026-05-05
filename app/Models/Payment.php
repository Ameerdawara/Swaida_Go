<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $fillable = [
    'subscription_id', 'month_number', 'year', 'amount', 
    'status', 'paid_at', 'payment_gateway_ref'
];

public function subscription()
{
    return $this->belongsTo(Subscription::class);
}

/**
 * منطق تحديد حالة الدفع تلقائياً حسب التاريخ الفعلي
 */
public static function getStatus($month, $year, $paid_at)
{
    if ($paid_at !== null) {
        return 'paid'; // مدفوع
    }

    $currentMonth = date('n');
    $currentYear = date('Y');

    if ($year < $currentYear || ($year == $currentYear && $month < $currentMonth)) {
        return 'overdue'; // متأخر (استحق ولم يدفع)
    }

    return 'pending'; // لم يستحق بعد[cite: 1]
}
}
