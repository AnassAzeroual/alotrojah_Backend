<?php

namespace App\Http\Requests;

use App\Enums\QuestionModel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreQuestionsBulkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', $this->route('exam'));
    }

    public function rules(): array
    {
        // ayah bounds checked in controller (need surah ayahs_count)
        return [
            'questions' => ['required', 'array', 'min:1', 'max:50'],
            'questions.*.question_no' => ['required', 'integer', 'min:1', 'max:100'],
            'questions.*.prompt_text' => ['nullable', 'string', 'max:500'],
            'questions.*.hizb_ref' => ['nullable', 'numeric', 'between:1,60'],
            'questions.*.surah_ref' => ['nullable', 'integer', 'exists:quran_verses,sura_no'],
            'questions.*.ayah_from' => ['nullable', 'integer', 'min:1'],
            'questions.*.ayah_to' => ['nullable', 'integer', 'min:1'],
            'questions.*.sort_order' => ['sometimes', 'integer', 'min:0'],
            'questions.*.model_type' => ['sometimes', Rule::enum(QuestionModel::class)],
            'questions.*.max_score' => ['required', 'numeric', 'min:0.01', 'max:20'],
            'questions.*.score' => ['nullable', 'numeric', 'min:0', 'max:20'],
            'questions.*.notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
