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
        Schema::create('charity_funds', function (Blueprint $table) {
        $table->id();
        $table->decimal('balance', 12, 2)->default(0); // رصيد الجمعية[cite: 1]
        $table->timestamp('last_updated')->useCurrent(); // آخر تحديث[cite: 1]
        $table->timestamps();
    });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('charity_funds');
    }
};
