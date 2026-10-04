<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Self-registration waiting room. Accept copies the row into users (+students),
// cancel deletes it. Staging table: no FKs, never center-scoped (admin-only).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registration_requests', function (Blueprint $table) {
            $table->id();
            $table->string('full_name', 150);
            $table->string('email', 150);
            $table->string('password_hash', 255);
            $table->enum('role', ['supervisor', 'teacher', 'student', 'board'])->default('teacher');
            $table->enum('teacher_type', ['hifz', 'murajaa', 'both'])->default('both');
            $table->string('phone', 30)->nullable();
            $table->date('birth_date')->nullable();
            $table->enum('gender', ['male', 'female'])->nullable();
            $table->timestamp('requested_at')->useCurrent();
            $table->unique('email', 'uq_regrequests_email');
            $table->index('role', 'idx_regrequests_role');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_requests');
    }
};
