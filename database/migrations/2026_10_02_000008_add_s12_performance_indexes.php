<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Composite lookups used by season aggregates, ordering and filters (S12).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->index(['student_id', 'season_id', 'term_id', 'exam_type'], 'idx_s12_exams_lookup');
        });

        Schema::table('exam_questions', function (Blueprint $table) {
            $table->index(['exam_id', 'sort_order', 'question_no'], 'idx_s12_exq_order');
        });

        Schema::table('session_scores', function (Blueprint $table) {
            $table->index(['student_id', 'season_id'], 'idx_s12_scores_season');
        });

        Schema::table('revision_logs', function (Blueprint $table) {
            $table->index(['student_id', 'season_id'], 'idx_s12_revlog_season');
        });

        Schema::table('murajaa_reviews', function (Blueprint $table) {
            $table->index(['student_id', 'season_id'], 'idx_s12_murajaa_season');
        });

        Schema::table('attendance', function (Blueprint $table) {
            $table->index(['student_id', 'season_id', 'term_id'], 'idx_s12_att_season');
        });

        Schema::table('term_results', function (Blueprint $table) {
            $table->index(['student_id', 'season_id'], 'idx_s12_termres_season');
        });

        Schema::table('memorization_logs', function (Blueprint $table) {
            $table->index(['student_id', 'season_id'], 'idx_s12_memolog_season');
        });
    }

    public function down(): void
    {
        Schema::table('memorization_logs', function (Blueprint $table) {
            $table->dropIndex('idx_s12_memolog_season');
        });

        Schema::table('term_results', function (Blueprint $table) {
            $table->dropIndex('idx_s12_termres_season');
        });

        Schema::table('attendance', function (Blueprint $table) {
            $table->dropIndex('idx_s12_att_season');
        });

        Schema::table('murajaa_reviews', function (Blueprint $table) {
            $table->dropIndex('idx_s12_murajaa_season');
        });

        Schema::table('revision_logs', function (Blueprint $table) {
            $table->dropIndex('idx_s12_revlog_season');
        });

        Schema::table('session_scores', function (Blueprint $table) {
            $table->dropIndex('idx_s12_scores_season');
        });

        Schema::table('exam_questions', function (Blueprint $table) {
            $table->dropIndex('idx_s12_exq_order');
        });

        Schema::table('exams', function (Blueprint $table) {
            $table->dropIndex('idx_s12_exams_lookup');
        });
    }
};
