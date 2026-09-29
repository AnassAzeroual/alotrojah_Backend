<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreScoresBulkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', \App\Models\SessionScore::class);
    }

    public function rules(): array
    {
        // module existence + score range checked per row in ScoreEntryService
        // (max comes from the module row, not from code)
        return [
            'session_id' => ['required', 'integer', 'exists:sessions,id'],
            'records' => ['required', 'array', 'min:1', 'max:400'],
            'records.*.student_id' => ['required', 'integer', 'exists:students,id'],
            'records.*.module_code' => ['required', 'string', 'max:40'],
            'records.*.score' => ['required', 'numeric'],
        ];
    }
}
