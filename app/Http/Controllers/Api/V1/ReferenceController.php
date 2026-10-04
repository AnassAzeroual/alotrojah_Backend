<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Level;
use App\Models\QuranVerse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Read-only dropdown feeds (any authenticated user). */
class ReferenceController extends Controller
{
    public function levels(): JsonResponse
    {
        return $this->ok(Level::orderBy('id')->get());
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
