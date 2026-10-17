<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Full background engine (user request 2026-10-17): background modes (solid
// color, uploaded image, CSS gradient), free-form size/position, repeat,
// attachment, blend mode, non-destructive pattern tint/invert and a
// glassmorphism layer. Defaults reproduce the pre-engine look, and centers
// that already have an uploaded background keep showing it.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appearance_settings', function (Blueprint $table) {
            $table->string('background_type', 16)->default('none')->after('background_path');
            $table->string('background_color', 7)->default('#0e9f6e')->after('background_type');
            $table->string('gradient_type', 8)->default('linear')->after('background_color');
            $table->unsignedSmallInteger('gradient_angle')->default(135)->after('gradient_type');
            $table->string('gradient_from', 7)->default('#0e9f6e')->after('gradient_angle');
            $table->string('gradient_to', 7)->default('#0b3b2f')->after('gradient_from');
            $table->string('background_repeat', 16)->default('no-repeat')->after('background_position');
            $table->string('background_attachment', 8)->default('scroll')->after('background_repeat');
            $table->string('background_blend_mode', 24)->default('normal')->after('background_attachment');
            $table->boolean('pattern_enabled')->default(true)->after('pattern_path');
            $table->string('pattern_tint', 7)->nullable()->after('pattern_size');
            $table->boolean('pattern_invert')->default(false)->after('pattern_tint');
            $table->unsignedTinyInteger('backdrop_blur')->default(0)->after('pattern_invert');
            $table->string('glass_tint', 7)->default('#ffffff')->after('backdrop_blur');
            $table->unsignedTinyInteger('glass_opacity')->default(0)->after('glass_tint');
        });

        // Custom sizes/positions need room for e.g. "320px 200px" or
        // "right 20px bottom 10px"; legacy values stay valid.
        Schema::table('appearance_settings', function (Blueprint $table) {
            $table->string('background_size', 64)->default('cover')->change();
            $table->string('background_position', 64)->default('center')->change();
        });

        DB::table('appearance_settings')
            ->whereNotNull('background_path')
            ->update(['background_type' => 'image']);
    }

    public function down(): void
    {
        // Narrowing the columns again requires dropping free-form values.
        DB::table('appearance_settings')
            ->whereNotIn('background_size', ['cover', 'contain', 'fill', 'tile'])
            ->update(['background_size' => 'cover']);
        DB::table('appearance_settings')
            ->whereNotIn('background_position', [
                'center', 'top', 'bottom', 'left', 'right',
                'top-left', 'top-right', 'bottom-left', 'bottom-right',
            ])
            ->update(['background_position' => 'center']);

        Schema::table('appearance_settings', function (Blueprint $table) {
            $table->string('background_size', 10)->default('cover')->change();
            $table->string('background_position', 20)->default('center')->change();
        });

        Schema::table('appearance_settings', function (Blueprint $table) {
            $table->dropColumn([
                'background_type',
                'background_color',
                'gradient_type',
                'gradient_angle',
                'gradient_from',
                'gradient_to',
                'background_repeat',
                'background_attachment',
                'background_blend_mode',
                'pattern_enabled',
                'pattern_tint',
                'pattern_invert',
                'backdrop_blur',
                'glass_tint',
                'glass_opacity',
            ]);
        });
    }
};
