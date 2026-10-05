<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Center;
use App\Models\Level;
use App\Models\QuranVerse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Read-only dropdown feeds (any authenticated user). */
class ReferenceController extends Controller
{
    public function levels(Request $request): JsonResponse
    {
        // Copy-on-write sets: overrides win, shared defaults fill the gaps.
        // No param (or unknown center) = the default template, as before.
        $centerId = $request->filled('center_id') ? (int) $request->input('center_id') : null;
        if ($centerId !== null && ! Center::where('id', $centerId)->exists()) {
            $centerId = null;
        }

        return $this->ok(Level::effectiveFor($centerId)->values());
    }

    public function surahs(Request $request): JsonResponse
    {
        // Item 11: same JSON shape as the old `surahs` stub
        // ({id, name_ar, ayahs_count}) computed from `quran_verses`.
        $q = QuranVerse::query()
            ->selectRaw('sura_no as id, MIN(surah_name) as name_ar, COUNT(*) as ayahs_count')
            ->groupBy('sura_no')
            ->orderBy('sura_no');
        if ($request->filled('q')) $q->where('surah_name', 'like', '%'.$request->input('q').'%');

        return $this->ok($q->get());
    }
}
