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
       Schema::create('payments', function (Blueprint $table) {
        $table->id();
        $table->foreignId('subscription_id')->constrained('subscriptions')->onDelete('cascade');
        $table->tinyInteger('month_number'); // رقم الشهر (1 - 12)[cite: 1]
        $table->smallInteger('year'); // السنة[cite: 1]
        $table->decimal('amount', 8, 2); // مبلغ الدفعة[cite: 1]
        $table->enum('status', ['pending', 'paid', 'overdue'])->default('pending'); // حالة الدفع[cite: 1]
        $table->timestamp('paid_at')->nullable(); // تاريخ الدفع الفعلي[cite: 1]
        $table->string('payment_gateway_ref')->nullable(); // المرجع المالي من بوابة الدفع[cite: 1]
        $table->timestamps();
    });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
