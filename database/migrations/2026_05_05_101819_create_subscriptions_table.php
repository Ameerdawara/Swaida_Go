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
      Schema::create('subscriptions', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->constrained('users')->onDelete('cascade'); // مفتاح أجنبي للمستخدم[cite: 1]
        $table->decimal('annual_amount', 8, 2); // المبلغ السنوي[cite: 1]
        $table->decimal('monthly_amount', 8, 2); // القسط الشهري (السنوي ÷ 12)[cite: 1]
        $table->date('start_date'); // تاريخ بدء الاشتراك[cite: 1]
        $table->enum('status', ['active', 'suspended', 'cancelled'])->default('active'); // حالة الاشتراك[cite: 1]
        $table->timestamps();
    });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
