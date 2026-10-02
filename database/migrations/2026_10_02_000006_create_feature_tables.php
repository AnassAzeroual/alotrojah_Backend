<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Baseline schema, must match docs/database/quran_memorization_db.sql exactly.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delegation_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('group_id');
            $table->unsignedBigInteger('granter_teacher_id');
            $table->char('token', 64);
            $table->unsignedSmallInteger('duration_minutes')->default(30);
            $table->dateTime('expires_at');
            $table->unsignedBigInteger('used_by_teacher_id')->nullable();
            $table->dateTime('used_at')->nullable();
            $table->boolean('is_revoked')->default(0);
            $table->timestamp('created_at')->useCurrent();
            $table->unique('token', 'uq_delegation_token');
            $table->index('group_id', 'idx_delegation_group');
            $table->foreign('group_id', 'fk_delegation_group')->references('id')->on('groups')->onDelete('cascade');
            $table->foreign('granter_teacher_id', 'fk_delegation_granter')->references('id')->on('users');
            $table->foreign('used_by_teacher_id', 'fk_delegation_usedby')->references('id')->on('users');
        });

        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('author_id');
            $table->enum('audience', ['all', 'teachers', 'manager', 'my_students'])->default('all');
            $table->unsignedBigInteger('group_id')->nullable();
            $table->string('title', 200);
            $table->text('body');
            $table->timestamp('created_at')->useCurrent();
            $table->index('audience', 'idx_ann_audience');
            $table->foreign('author_id', 'fk_ann_author')->references('id')->on('users');
            $table->foreign('group_id', 'fk_ann_group')->references('id')->on('groups')->onDelete('cascade');
        });

        Schema::create('notifications_log', function (Blueprint $table) {
            $table->id();
            $table->string('recipient_phone', 30);
            $table->enum('channel', ['whatsapp', 'other'])->default('whatsapp')->comment('wa.me links, no paid API');
            $table->text('message');
            $table->enum('status', ['queued', 'sent', 'failed'])->default('queued');
            $table->unsignedBigInteger('sent_by')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->index('status', 'idx_notif_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications_log');
        Schema::dropIfExists('announcements');
        Schema::dropIfExists('delegation_tokens');
    }
};
