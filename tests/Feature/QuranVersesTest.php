<?php

namespace Tests\Feature;

use App\Models\QuranVerse;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use LogicException;
use Tests\TestCase;
use Throwable;

/** Item 11: `quran_verses` replaces the `surahs` stub (Tanzil, machine-imported). */
class QuranVersesTest extends TestCase
{
    public function test_verses_are_read_only(): void
    {
        // Creating blocked ($guarded fires MassAssignmentException before events).
        try {
            QuranVerse::create(['sura_no' => 1, 'surah_name' => 'x', 'ayah_no' => 1, 'text' => 'x']);
            $this->fail('QuranVerse::create must throw.');
        } catch (Throwable $e) {
            $this->assertNotSame('', $e->getMessage());
        }

        // Updating blocked (forceFill bypasses $guarded so the event guard fires; row untouched).
        $verse = QuranVerse::firstOrFail();
        $original = $verse->text;
        try {
            $verse->forceFill(['text' => 'x'])->save();
            $this->fail('QuranVerse::update must throw.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('read-only', $e->getMessage());
        }
        $this->assertSame($original, $verse->fresh()->text);

        // Deleting blocked (row survives).
        try {
            $verse->delete();
            $this->fail('QuranVerse::delete must throw.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('read-only', $e->getMessage());
        }
        $this->assertSame(6236, DB::table('quran_verses')->count());
    }

    public function test_verses_table_has_full_quran(): void
    {
        $this->assertTrue(Schema::hasTable('quran_verses'));
        $this->assertFalse(Schema::hasTable('surahs'));
        $this->assertSame(6236, DB::table('quran_verses')->count());
        $this->assertSame(114, DB::table('quran_verses')->distinct()->count('sura_no'));

        // Known per-sura counts (numbers only — verse text never hand-typed).
        foreach ([1 => 7, 2 => 286, 112 => 4, 113 => 5, 114 => 6] as $sura => $count) {
            $this->assertSame($count, DB::table('quran_verses')->where('sura_no', $sura)->count());
            $this->assertSame($count, DB::table('quran_verses')->where('sura_no', $sura)->max('ayah_no'));
        }

        // No empty or mojibake rows (U+FFFD = failed UTF-8 import).
        $this->assertSame(0, DB::table('quran_verses')->where('text', '')->count());
        $this->assertSame(0, DB::table('quran_verses')->where('text', 'like', "%\u{FFFD}%")->count());
        $this->assertSame(0, DB::table('quran_verses')->where('surah_name', '')->count());

        // exists: rule wiring for the new column.
        $this->assertFalse(Validator::make(['s' => 2], ['s' => 'exists:quran_verses,sura_no'])->fails());
        $this->assertTrue(Validator::make(['s' => 115], ['s' => 'exists:quran_verses,sura_no'])->fails());
    }

    public function test_surahs_feed_keeps_shape(): void
    {
        $this->actingAs(User::find(1), 'api');
        $data = $this->getJson('/api/v1/reference/surahs')->assertOk()->json('data');
        $this->assertCount(114, $data);
        $this->assertSame(['id', 'name_ar', 'ayahs_count'], array_keys($data[0]));
        $this->assertSame(1, (int) $data[0]['id']);
        $this->assertSame(7, (int) $data[0]['ayahs_count']);
    }
}
