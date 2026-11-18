<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submission_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_assignment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_submission_id')->constrained()->cascadeOnDelete();
            $table->uuid('idempotency_key');
            $table->uuid('receipt_id')->unique();
            $table->char('payload_sha256', 64);
            $table->timestamps();
            $table->unique(['user_id', 'task_assignment_id', 'idempotency_key'], 'submission_receipts_idempotency_unique');
        });
    }

    public function down(): void
    {
        // Do not run migrate:rollback for a live queue: receipts protect against duplicates.
        Schema::dropIfExists('submission_receipts');
    }
};
