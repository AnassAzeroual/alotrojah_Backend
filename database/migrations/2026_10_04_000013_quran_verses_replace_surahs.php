<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Item 11 (2026-10-04): replace the `surahs` stub (id/name/count) with the
// full Tanzil verse text. Verse rows are machine-parsed from
// `quran-simple.sql` READ-ONLY (holy text never hand-typed, file untouched);
// surah names are denormalized from the old `surahs` table before it drops.
// License: Tanzil CC-BY 3.0 (attribution in README).
//
// QURAN IMMUTABILITY LAW (applies to every future agent and every deploy):
// this migration is the ONLY writer to `quran_verses`, ever. No update, no
// delete, no re-seed, no "fix the text" patch — from ANY code, seeder,
// tinker session, controller, or API endpoint. The Eloquent model
// (`QuranVerse`) throws on every write event as a second guard. If the
// upstream Tanzil source ever changes, the only legal path is a NEW
// migration that re-imports machine-side (file stays byte-identical).
return new class extends Migration
{
    public function up(): void
    {
        // Resume-guard: if a previous run was killed after the work but
        // before the ledger write (e.g. HTTP timeout on web-triggered
        // migrate), re-running must be a harmless no-op, not a crash.
        if (
            ! Schema::hasTable('surahs')
            && Schema::hasTable('quran_verses')
            && DB::table('quran_verses')->count() === 6236
        ) {
            return;
        }

        Schema::create('quran_verses', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->engine = 'InnoDB';
            $table->id();
            $table->unsignedSmallInteger('sura_no')->comment('1-114');
            $table->string('surah_name', 60);
            $table->unsignedSmallInteger('ayah_no');
            $table->mediumText('text');
            $table->unique(['sura_no', 'ayah_no'], 'uq_verses_sura_ayah');
            $table->index('sura_no', 'idx_verses_sura');
        });

        $names = DB::table('surahs')->pluck('name_ar', 'id');
        if ($names->count() !== 114) {
            throw new RuntimeException('Item 11: expected 114 surahs, got '.$names->count());
        }

        // Verse source: repo-local copy first (fresh clones + CI), legacy
        // sibling-of-backend path as fallback (original checkout layout).
        $path = database_path('data/quran-simple.sql');
        if (! is_file($path)) {
            $path = base_path('../quran-simple.sql');
        }
        if (! is_file($path)) {
            $path = dirname(base_path()).DIRECTORY_SEPARATOR.'quran-simple.sql';
        }
        if (! is_file($path)) {
            throw new RuntimeException('Item 11: quran-simple.sql not found (looked in database/data/).');
        }
        $sql = file_get_contents($path);
        if ($sql === false || $sql === '') {
            throw new RuntimeException('Item 11: quran-simple.sql unreadable.');
        }
        preg_match_all(
            "/\((\d+),\s*(\d+),\s*(\d+),\s*'((?:[^'\\\\]|\\\\.|\\''|'')*)'\)/",
            $sql,
            $m
        );
        $total = count($m[0]);
        if ($total !== 6236) {
            throw new RuntimeException('Item 11: expected 6236 verses, parsed '.$total);
        }

        $rows = [];
        $perSura = [];
        foreach ($m[2] as $i => $suraRaw) {
            $sura = (int) $suraRaw;
            $ayah = (int) $m[3][$i];
            $name = $names->get($sura);
            if ($name === null) {
                throw new RuntimeException("Item 11: no surah name for sura_no $sura");
            }
            $text = str_replace(["\\'", '\\\\', "''"], ["'", '\\', "'"], $m[4][$i]);
            if ($text === '') {
                throw new RuntimeException("Item 11: empty verse text at sura $sura ayah $ayah");
            }
            $rows[] = ['sura_no' => $sura, 'surah_name' => $name, 'ayah_no' => $ayah, 'text' => $text];
            $perSura[$sura] = max($perSura[$sura] ?? 0, $ayah);
        }

        // Per-sura parity against the old table BEFORE dropping it.
        foreach ($names as $id => $name) {
            $expected = (int) DB::table('surahs')->where('id', $id)->value('ayahs_count');
            if (($perSura[(int) $id] ?? 0) !== $expected) {
                throw new RuntimeException("Item 11: sura $id parity failed (file ".($perSura[(int) $id] ?? 0)." vs surahs $expected)");
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('quran_verses')->insert($chunk);
        }
        if (DB::table('quran_verses')->count() !== 6236) {
            throw new RuntimeException('Item 11: quran_verses row count mismatch after insert.');
        }

        Schema::dropIfExists('surahs');
    }

    public function down(): void
    {
        Schema::create('surahs', function (Blueprint $table) {
            $table->unsignedSmallInteger('id')->comment('1-114');
            $table->string('name_ar', 60);
            $table->unsignedSmallInteger('ayahs_count');
            $table->primary('id');
        });

        if (Schema::hasTable('quran_verses')) {
            $agg = DB::table('quran_verses')
                ->selectRaw('sura_no, MIN(surah_name) as name_ar, MAX(ayah_no) as ayahs_count')
                ->groupBy('sura_no')
                ->orderBy('sura_no')
                ->get();
            foreach ($agg as $row) {
                DB::table('surahs')->insertOrIgnore([
                    'id' => $row->sura_no, 'name_ar' => $row->name_ar, 'ayahs_count' => $row->ayahs_count,
                ]);
            }
            Schema::dropIfExists('quran_verses');
        }
    }
};
