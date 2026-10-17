<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The two ambient color washes ("orbs") used to be hardcoded in the shell and
// the live preview, so they showed even with background mode "none". Per user
// request 2026-10-17 they become per-center controls: a toggle, both colors and
// an intensity. Defaults reproduce the previous look exactly.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appearance_settings', function (Blueprint $table) {
            $table->boolean('orb_enabled')->default(true)->after('glass_opacity');
            $table->string('orb_primary', 7)->default('#00b8a9')->after('orb_enabled');
            $table->string('orb_secondary', 7)->default('#8b5cf6')->after('orb_primary');
            $table->unsignedTinyInteger('orb_opacity')->default(50)->after('orb_secondary');
        });
    }

    public function down(): void
    {
        Schema::table('appearance_settings', function (Blueprint $table) {
            $table->dropColumn(['orb_enabled', 'orb_primary', 'orb_secondary', 'orb_opacity']);
        });
    }
};
