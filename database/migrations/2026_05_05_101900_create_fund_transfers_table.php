<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
       Schema::create('fund_transfers', function (Blueprint $table) {
        $table->id();
        $table->decimal('amount', 12, 2); // المبلغ المحول[cite: 1]
        $table->string('recipient_name'); // اسم المستلم[cite: 1]
        $table->string('receipt_number'); // رقم الإيصال[cite: 1]
        $table->date('transfer_date'); // تاريخ التحويل[cite: 1]
        $table->text('note')->nullable(); // ملاحظات إضافية[cite: 1]
        $table->foreignId('created_by')->constrained('users'); // المدير الذي قام بالتحويل[cite: 1]
        $table->timestamps();
    });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fund_transfers');
    }
};
