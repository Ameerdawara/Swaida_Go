<?php

namespace App\Http\Controllers;

use App\Models\FundTransfer;
use App\Models\AppFund;
use App\Models\CharityFund;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class FundTransferController extends Controller
{
    // إجراء عملية تحويل من صندوق التطبيق إلى صندوق الجمعية
    public function store(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:1',
            'recipient_name' => 'required|string|max:255', // اسم الشخص الذي استلم المبلغ ورقياً
            'receipt_number' => 'required|string|unique:fund_transfers', // رقم الوصل الورقي
            'transfer_date' => 'required|date',
            'note' => 'nullable|string'
        ]);

        DB::beginTransaction();
        try {
            $appFund = AppFund::first();
            
            // التأكد من توفر رصيد كافٍ في التطبيق قبل التحويل
            if (!$appFund || $appFund->balance < $request->amount) {
                return response()->json(['message' => 'الرصيد في صندوق التطبيق غير كافٍ'], 400);
            }

            // 1. خصم من صندوق التطبيق
            $appFund->balance -= $request->amount;
            $appFund->save();

            // 2. إضافة إلى صندوق الجمعية (Charity Fund)
            $charityFund = CharityFund::firstOrCreate(['id' => 1]);
            $charityFund->balance += $request->amount;
            $charityFund->save();

            // 3. توثيق العملية في سجل التحويلات
            $transfer = FundTransfer::create([
                'amount' => $request->amount,
                'recipient_name' => $request->recipient_name,
                'receipt_number' => $request->receipt_number,
                'transfer_date' => $request->transfer_date,
                'note' => $request->note,
                'created_by' => $request->user()->id // المدير الحالي
            ]);

            DB::commit();
            return response()->json(['message' => 'تم التحويل وتوثيق السجل بنجاح', 'transfer' => $transfer], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'فشل التحويل', 'error' => $e->getMessage()], 500);
        }
    }

    // عرض تاريخ جميع التحويلات (للتقارير)
    public function index()
    {
        return response()->json(FundTransfer::with('admin:id,full_name')->orderBy('transfer_date', 'desc')->get());
    }
}