<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Baseline schema, must match docs/database/quran_memorization_db.sql exactly.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exams', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('season_id');
            $table->unsignedBigInteger('term_id')->nullable()->comment('NULL = final season exam');
            $table->enum('exam_type', ['hizb_completion', 'term_batch', 'final_season']);
            $table->date('exam_date')->nullable();
            $table->unsignedBigInteger('examiner_id')->nullable();
            $table->decimal('overall_avg', 4, 2)->nullable();
            $table->text('examiner_report')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['student_id', 'season_id'], 'idx_exams_student_season');
            $table->index('exam_type', 'idx_exams_type');
            $table->foreign('student_id', 'fk_exams_student')->references('id')->on('students')->onDelete('cascade');
            $table->foreign('examiner_id', 'fk_exams_examiner')->references('id')->on('users');
        });

        Schema::create('exam_questions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('exam_id');
            $table->unsignedTinyInteger('question_no');
            $table->string('prompt_text', 500)->nullable();
            $table->decimal('hizb_ref', 4, 1)->nullable();
            $table->unsignedSmallInteger('surah_ref')->nullable();
            $table->unsignedSmallInteger('ayah_from')->nullable();
            $table->unsignedSmallInteger('ayah_to')->nullable();
            $table->unsignedTinyInteger('sort_order')->default(0)->comment('teacher reorders');
            $table->enum('model_type', ['model1', 'model2_full', 'single'])->nullable()->default('single');
            $table->decimal('score', 4, 2)->nullable();
            $table->string('notes', 500)->nullable();
            $table->unique(['exam_id', 'question_no'], 'uq_exq_exam_qno');
            $table->foreign('exam_id', 'fk_exq_exam')->references('id')->on('exams')->onDelete('cascade');
        });

        DB::statement('ALTER TABLE `exam_questions` ADD CONSTRAINT `chk_exq_score` CHECK (`score` IS NULL OR (`score` BETWEEN 0 AND 20))');

        Schema::create('term_results', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('season_id');
            $table->unsignedBigInteger('term_id');
            $table->decimal('hifz_total', 4, 2)->nullable();
            $table->decimal('murajaa_total', 4, 2)->nullable();
            $table->decimal('exam_score', 4, 2)->nullable();
            $table->decimal('general_avg', 4, 2)->nullable();
            $table->text('teacher_notes')->nullable();
            $table->text('supervisor_note')->nullable();
            $table->enum('honor_flag', ['none', 'tashji3', 'intibah'])->default('none');
            $table->unique(['student_id', 'term_id'], 'uq_termres_student_term');
            $table->foreign('student_id', 'fk_termres_student')->references('id')->on('students')->onDelete('cascade');
        });

        Schema::create('season_results', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('season_id');
            $table->string('total_memorized_label', 200)->nullable();
            $table->decimal('total_memorized_thumn', 7, 2)->nullable();
            $table->decimal('hifz_total', 4, 2)->nullable();
            $table->decimal('murajaa_total', 4, 2)->nullable();
            $table->decimal('overall_avg', 4, 2)->nullable();
            $table->text('board_report')->nullable();
            $table->enum('honor_flag', ['none', 'tashji3', 'intibah'])->default('none');
            $table->unique(['student_id', 'season_id'], 'uq_seasonres_student_season');
            $table->foreign('student_id', 'fk_seasonres_student')->references('id')->on('students')->onDelete('cascade');
            $table->foreign('season_id', 'fk_seasonres_season')->references('id')->on('academic_seasons')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('season_results');
        Schema::dropIfExists('term_results');
        Schema::dropIfExists('exam_questions');
        Schema::dropIfExists('exams');
    }
};
