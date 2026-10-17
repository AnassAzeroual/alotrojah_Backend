<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// App-wide appearance (user request 2026-10-17): one singleton row holding an
// uploaded background image and a repeating pattern image. Files live on the
// public disk; the API serves them through a public route because this host
// has no `public/storage` symlink.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appearance_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('background_path', 255)->nullable();
            $table->string('pattern_path', 255)->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->foreign('updated_by', 'fk_appearance_settings_updated_by')
                ->references('id')->on('users')->nullOnDelete();
        });

        DB::table('appearance_settings')->insert([
            'id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('appearance_settings');
    }
};
