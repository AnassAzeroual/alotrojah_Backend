<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMurajaaReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageReviews', \App\Models\MurajaaReview::class);
    }

    public function rules(): array
    {
        return [
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'term_id' => ['required', 'integer', 'exists:terms,id'],
            'week_from' => ['required', 'integer', 'min:1'],
            'week_to' => ['required', 'integer', 'min:1'],
            'session_id' => ['nullable', 'integer', 'exists:sessions,id'],
            'hizb_from' => ['nullable', 'numeric', 'between:1,60'],
            'hizb_to' => ['nullable', 'numeric', 'between:1,60'],
            'surah_from' => ['nullable', 'integer', 'exists:quran_verses,sura_no'],
            'ayah_from' => ['nullable', 'integer', 'min:1'],
            'surah_to' => ['nullable', 'integer', 'exists:quran_verses,sura_no'],
            'ayah_to' => ['nullable', 'integer', 'min:1'],
            'score' => ['required', 'numeric', 'min:0', 'max:20'],
            'reviewed_at' => ['nullable', 'date'],
        ];
    }
}
