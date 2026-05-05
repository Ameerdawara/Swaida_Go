<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FundTransfer extends Model
{
    protected $fillable = [
    'amount', 'recipient_name', 'receipt_number', 
    'transfer_date', 'note', 'created_by'
];

public function admin()
{
    return $this->belongsTo(User::class, 'created_by');
}
}
