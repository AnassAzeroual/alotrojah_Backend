<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Baseline schema, must match docs/database/quran_memorization_db.sql exactly.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('full_name', 150);
            $table->string('email', 150);
            $table->string('password_hash', 255)->nullable();
            $table->enum('role', ['admin', 'supervisor', 'teacher', 'student', 'board'])->default('teacher');
            $table->string('phone', 30)->nullable();
            $table->unsignedBigInteger('center_id')->nullable()->comment('NULL = global (system admin)');
            $table->enum('teacher_type', ['hifz', 'murajaa', 'both'])->default('both')->comment('تحفيظ / مراجعة / كلاهما — enables page inputs');
            $table->boolean('is_active')->default(1);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->unique('email', 'uq_users_email');
            $table->index('role', 'idx_users_role');
            $table->index('center_id', 'idx_users_center');
        });

        Schema::create('centers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('city', 100)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('manager_name', 150)->nullable();
        });

        Schema::create('levels', function (Blueprint $table) {
            $table->increments('id');
            $table->enum('code', ['L1', 'L2', 'L3'])->default('L1');
            $table->string('name_ar', 100);
            $table->unsignedTinyInteger('sessions_per_week')->default(3);
            $table->string('thumn_per_session_label', 50);
            $table->decimal('thumn_per_session_value', 5, 2);
            $table->decimal('thumn_per_week_value', 5, 2);
            $table->decimal('ahzab_per_term', 5, 2);
            $table->decimal('ahzab_per_dawra', 5, 2);
            $table->string('duration_label', 100);
            $table->unsignedTinyInteger('total_ahzab')->default(60);
            $table->unique('code', 'uq_levels_code');
        });

        Schema::create('groups', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('center_id');
            $table->unsignedInteger('level_id');
            $table->unsignedBigInteger('teacher_id')->nullable();
            $table->string('name', 120);
            $table->string('academic_year', 20)->nullable();
            $table->unsignedSmallInteger('capacity')->nullable()->default(20);
            $table->string('schedule_days', 60)->default('Mon,Wed,Fri')->comment('أيام الحصص — editable by teacher');
            $table->boolean('is_active')->default(1);
            $table->index('center_id', 'idx_groups_center');
            $table->index('level_id', 'idx_groups_level');
            $table->index('teacher_id', 'idx_groups_teacher');
            $table->foreign('center_id', 'fk_groups_center')->references('id')->on('centers');
            $table->foreign('level_id', 'fk_groups_level')->references('id')->on('levels');
            $table->foreign('teacher_id', 'fk_groups_teacher')->references('id')->on('users');
        });

        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('group_id')->nullable();
            $table->unsignedBigInteger('center_id')->nullable();
            $table->unsignedInteger('level_id')->nullable();
            $table->string('full_name', 150);
            $table->date('birth_date')->nullable();
            $table->enum('gender', ['male', 'female'])->nullable();
            $table->date('enrollment_date')->nullable();
            $table->enum('status', ['active', 'paused', 'graduated', 'left'])->default('active');
            $table->enum('student_type', ['child', 'adult'])->nullable()->comment('أطفال +4 / كبار +18');
            $table->enum('memorization_mode', ['surah', 'thumn'])->default('thumn')->comment('الحفظ بالسورة أو بالثمن');
            $table->decimal('start_hizb', 4, 1)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index('group_id', 'idx_students_group');
            $table->index('level_id', 'idx_students_level');
            $table->index('center_id', 'idx_students_center');
            $table->index('status', 'idx_students_status');
            $table->foreign('group_id', 'fk_students_group')->references('id')->on('groups');
            $table->foreign('level_id', 'fk_students_level')->references('id')->on('levels');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
        Schema::dropIfExists('groups');
        Schema::dropIfExists('levels');
        Schema::dropIfExists('centers');
        Schema::dropIfExists('users');
    }
};
