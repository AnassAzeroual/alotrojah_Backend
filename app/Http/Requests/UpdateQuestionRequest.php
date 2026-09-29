<?php

namespace App\Http\Requests;

use App\Enums\QuestionModel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', $this->route('exam_question')->exam);
    }

    public function rules(): array
    {
        return [
            'prompt_text' => ['sometimes', 'nullable', 'string', 'max:500'],
            'hizb_ref' => ['sometimes', 'nullable', 'numeric', 'between:1,60'],
            'surah_ref' => ['sometimes', 'nullable', 'integer', 'exists:surahs,id'],
            'ayah_from' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'ayah_to' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'model_type' => ['sometimes', Rule::enum(QuestionModel::class)],
            'score' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:20'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
