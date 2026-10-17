<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Display controls for the uploaded appearance layers (user request
// 2026-10-17): how the background is fitted/placed and how strong/large each
// layer is. Defaults preserve the pre-controls look.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appearance_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('background_opacity')->default(100)->after('background_path');
            $table->string('background_size', 10)->default('cover')->after('background_opacity');
            $table->string('background_position', 20)->default('center')->after('background_size');
            $table->unsignedTinyInteger('pattern_opacity')->default(16)->after('pattern_path');
            $table->unsignedSmallInteger('pattern_size')->default(120)->after('pattern_opacity');
        });
    }

    public function down(): void
    {
        Schema::table('appearance_settings', function (Blueprint $table) {
            $table->dropColumn([
                'background_opacity',
                'background_size',
                'background_position',
                'pattern_opacity',
                'pattern_size',
            ]);
        });
    }
};
