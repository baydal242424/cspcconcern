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
        Schema::create('referrals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('concern_id');
            $table->unsignedBigInteger('referred_by');
            $table->unsignedBigInteger('referred_to');
            $table->string('reason');
            $table->text('referral_notes')->nullable();
            $table->string('status')->default('pending'); // pending, accepted, in_progress, closed
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreign('concern_id')->references('id')->on('concerns')->onDelete('cascade');
            $table->foreign('referred_by')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('referred_to')->references('id')->on('users')->onDelete('restrict');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('referrals');
    }
};
