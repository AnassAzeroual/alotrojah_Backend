<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\LevelResource;
use App\Models\AcademicSeason;
use App\Models\Center;
use App\Models\Level;
use App\Models\QuranVerse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Read-only dropdown feeds (any authenticated user). */
class ReferenceController extends Controller
{
    /**
     * Level dropdown feed, season-scoped: `?season_id=` that season's set,
     * `?center_id=` that center's current (else latest) season, with the
     * legacy shared season as fallback. A center that has no season yet has no
     * levels, and no scope never returns a global set (empty feed).
     *
     * @response array{success: bool, message: string|null, data: list<LevelResource>}
     */
    public function levels(Request $request): JsonResponse
    {
        $season = null;
        if ($request->filled('season_id')) {
            $season = AcademicSeason::findOrFail((int) $request->input('season_id'));
            $this->authorize('view', $season);
        } elseif ($request->filled('center_id')) {
            $centerId = (int) $request->input('center_id');
            $season = Center::where('id', $centerId)->exists()
                ? AcademicSeason::currentFor($centerId)
                : null;
            if ($season === null) {
                return $this->ok([]);
            }
        }

        $levels = $season !== null
            ? Level::forSeason($season->id)->orderBy('id')->get()
            : collect();

        $terms = $season !== null
            ? $season->terms()->with('weeks')->orderBy('term_number')->get()
            : collect();

        return $this->ok($levels
            ->map(fn (Level $level) => (new LevelResource($level, $level->termGoals($terms)))->toArray())
            ->all());
    }

    /**
     * Surah dropdown feed ({id, name_ar, ayahs_count}) computed from quran_verses.
     *
     * Optional q filters by Arabic name substring. The rows are aggregates
     * aliased off QuranVerse, so the shape is declared explicitly (Scramble
     * would otherwise infer the QuranVerse model) and cast to native scalars.
     *
     * @response array{success: bool, message: null, data: list<array{id: int, name_ar: string, ayahs_count: int}>}
     */
    public function surahs(Request $request): JsonResponse
    {
        // Item 11: same JSON shape as the old `surahs` stub
        // ({id, name_ar, ayahs_count}) computed from `quran_verses`.
        $q = QuranVerse::query()
            ->selectRaw('sura_no as id, MIN(surah_name) as name_ar, COUNT(*) as ayahs_count')
            ->groupBy('sura_no')
            ->orderBy('sura_no');
        if ($request->filled('q')) {
            $q->where('surah_name', 'like', '%'.$request->input('q').'%');
        }

        return $this->ok(
            $q->get()
                ->map(fn ($r) => [
                    'id' => (int) $r->getAttribute('id'),
                    'name_ar' => (string) $r->getAttribute('name_ar'),
                    'ayahs_count' => (int) $r->getAttribute('ayahs_count'),
                ])
                ->values()
        );
    }
}
