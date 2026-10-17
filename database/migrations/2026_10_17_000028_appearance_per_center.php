<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Appearance is per center (user request 2026-10-17): every center keeps its
// own background, pattern and display controls. The old singleton row is
// transferred to its author's center when that author belongs to one; a
// center with no row renders the shipped defaults (no background, star tile).
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('appearance_settings', 'appearance_settings_legacy');

        Schema::create('appearance_settings', function (Blueprint $table) {
            $table->unsignedBigInteger('center_id')->primary();
            $table->string('background_path', 255)->nullable();
            $table->unsignedTinyInteger('background_opacity')->default(100);
            $table->string('background_size', 10)->default('cover');
            $table->string('background_position', 20)->default('center');
            $table->string('pattern_path', 255)->nullable();
            $table->unsignedTinyInteger('pattern_opacity')->default(16);
            $table->unsignedSmallInteger('pattern_size')->default(120);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->foreign('center_id', 'fk_appearance_settings_center')
                ->references('id')->on('centers')->cascadeOnDelete();
            // v2: the legacy table still owns the original constraint name
            // until it is dropped below; InnoDB names are schema-wide.
            $table->foreign('updated_by', 'fk_appearance_settings_updated_by_v2')
                ->references('id')->on('users')->nullOnDelete();
        });

        $legacy = DB::table('appearance_settings_legacy')->where('id', 1)->first();
        if ($legacy !== null) {
            $centerId = $legacy->updated_by
                ? DB::table('users')->where('id', $legacy->updated_by)->value('center_id')
                : null;

            if ($centerId !== null && DB::table('centers')->where('id', $centerId)->exists()) {
                DB::table('appearance_settings')->insert([
                    'center_id' => (int) $centerId,
                    'background_path' => $legacy->background_path,
                    'background_opacity' => $legacy->background_opacity,
                    'background_size' => $legacy->background_size,
                    'background_position' => $legacy->background_position,
                    'pattern_path' => $legacy->pattern_path,
                    'pattern_opacity' => $legacy->pattern_opacity,
                    'pattern_size' => $legacy->pattern_size,
                    'updated_by' => $legacy->updated_by,
                    'created_at' => $legacy->created_at,
                    'updated_at' => $legacy->updated_at,
                ]);
            }
        }

        Schema::drop('appearance_settings_legacy');
    }

    public function down(): void
    {
        $row = DB::table('appearance_settings')->orderBy('center_id')->first();

        Schema::drop('appearance_settings');

        Schema::create('appearance_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('background_path', 255)->nullable();
            $table->unsignedTinyInteger('background_opacity')->default(100);
            $table->string('background_size', 10)->default('cover');
            $table->string('background_position', 20)->default('center');
            $table->string('pattern_path', 255)->nullable();
            $table->unsignedTinyInteger('pattern_opacity')->default(16);
            $table->unsignedSmallInteger('pattern_size')->default(120);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->foreign('updated_by', 'fk_appearance_settings_updated_by')
                ->references('id')->on('users')->nullOnDelete();
        });

        DB::table('appearance_settings')->insert([
            'id' => 1,
            'background_path' => $row?->background_path,
            'background_opacity' => $row?->background_opacity ?? 100,
            'background_size' => $row?->background_size ?? 'cover',
            'background_position' => $row?->background_position ?? 'center',
            'pattern_path' => $row?->pattern_path,
            'pattern_opacity' => $row?->pattern_opacity ?? 16,
            'pattern_size' => $row?->pattern_size ?? 120,
            'updated_by' => $row?->updated_by,
            'created_at' => $row?->created_at ?? now(),
            'updated_at' => $row?->updated_at ?? now(),
        ]);
    }
};
