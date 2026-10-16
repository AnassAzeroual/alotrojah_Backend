<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReweightExamQuestionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageExam', $this->route('exam'));
    }

    public function rules(): array
    {
        return [
            'weights' => ['required', 'array', 'min:1'],
            'weights.*.id' => ['required', 'integer'],
            'weights.*.max_score' => ['required', 'numeric', 'min:0.01', 'max:20'],
        ];
    }
}
