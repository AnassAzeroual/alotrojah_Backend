<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Level;
use App\Models\Surah;
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
        $q = Surah::orderBy('id');
        if ($request->filled('q')) $q->where('name_ar', 'like', '%'.$request->input('q').'%');

        return $this->ok($q->get());
    }
}
