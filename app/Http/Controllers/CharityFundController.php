<?php

namespace App\Http\Controllers;

use App\Models\CharityFund;

class CharityFundController extends Controller
{
    public function index()
    {
        $fund = CharityFund::first();
        return response()->json([
            'fund_name' => 'صندوق الجمعية (الرصيد المحول)',
            'balance' => $fund ? $fund->balance : 0,
            'last_updated' => $fund ? $fund->last_updated : null
        ]);
    }
}