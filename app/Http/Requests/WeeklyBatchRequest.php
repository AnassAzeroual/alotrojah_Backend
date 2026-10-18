<?php

namespace App\Http\Requests;

use App\Models\Exam;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\IgnoreParam;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `rows.*.scores` is keyed by scoring-module code (a map), but the `scores.*`
 * rule makes Scramble infer a numeric list — so the wildcard child is hidden
 * from the docs and the map type is declared explicitly. Runtime rules are
 * unchanged; this only corrects the generated OpenAPI/TS shape.
 */
#[IgnoreParam('rows.*.scores.*', 'body')]
#[BodyParameter('rows.*.scores', type: 'array<string, float|null>', infer: true)]
class WeeklyBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', Exam::class);
    }

    public function rules(): array
    {
        return [
            'group_id' => ['required', 'integer', 'exists:groups,id'],
            'exam_date' => ['required', 'date'],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.student_id' => ['required', 'integer', 'exists:students,id'],
            'rows.*.portion' => ['required', 'array'],
            'rows.*.portion.hizb' => ['nullable', 'numeric', 'between:1,60'],
            'rows.*.portion.thumn' => ['nullable', 'integer', 'between:1,8'],
            'rows.*.portion.surah' => ['nullable', 'integer', 'min:1'],
            'rows.*.portion.ayah_from' => ['nullable', 'integer', 'min:1'],
            'rows.*.portion.ayah_to' => ['nullable', 'integer', 'min:1'],
            'rows.*.scores' => ['required', 'array'],
            'rows.*.scores.*' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
