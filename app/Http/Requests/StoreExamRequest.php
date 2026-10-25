<?php

namespace App\Http\Requests;

use App\Enums\ExamType;
use App\Enums\QuestionModel;
use App\Models\Exam;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', Exam::class);
    }

    public function rules(): array
    {
        return [
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'exam_type' => ['required', Rule::enum(ExamType::class)],
            'term_id' => ['required_if:exam_type,term_batch,hizb_completion', 'nullable', 'integer', 'exists:terms,id'],
            'season_id' => ['required_if:exam_type,final_season,weekly', 'nullable', 'integer', 'exists:academic_seasons,id'],
            'exam_date' => ['nullable', 'date'],
            'examiner_id' => ['nullable', 'integer', 'exists:users,id'],
            'examiner_report' => ['nullable', 'string'],
            // A live test may be submitted with its questions in the same
            // request, so exam + results are one transaction (no shell).
            'questions' => ['nullable', 'array', 'min:1', 'max:50'],
            'questions.*.question_no' => ['required', 'integer', 'min:1', 'max:100'],
            'questions.*.prompt_text' => ['nullable', 'string', 'max:500'],
            'questions.*.hizb_ref' => ['nullable', 'numeric', 'between:1,60'],
            'questions.*.thumn_ref' => ['nullable', 'integer', 'between:1,8'],
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
