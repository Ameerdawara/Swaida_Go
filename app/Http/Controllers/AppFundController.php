<?php

namespace App\Http\Controllers;

use App\Models\AppFund;

class AppFundController extends Controller
{
    public function index()
    {
        $fund = AppFund::first();
        return response()->json([
            'fund_name' => 'صندوق التطبيق الإلكتروني',
            'balance' => $fund ? $fund->balance : 0,
            'last_updated' => $fund ? $fund->last_updated : null
        ]);
    }
}