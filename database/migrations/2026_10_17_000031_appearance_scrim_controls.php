<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The contrast scrim above the user background (and every other layer) used to
// be hardcoded at 72% of the theme surface (80% in dark mode), so it could not
// be tuned. Per user request 2026-10-17 it becomes a per-center control: a
// toggle, an optional custom colour (null keeps the theme surface) and a
// strength. Defaults reproduce the previous look exactly.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appearance_settings', function (Blueprint $table) {
            $table->boolean('scrim_enabled')->default(true)->after('orb_opacity');
            $table->string('scrim_color', 7)->nullable()->after('scrim_enabled');
            $table->unsignedTinyInteger('scrim_opacity')->default(72)->after('scrim_color');
        });
    }

    public function down(): void
    {
        Schema::table('appearance_settings', function (Blueprint $table) {
            $table->dropColumn(['scrim_enabled', 'scrim_color', 'scrim_opacity']);
        });
    }
};
