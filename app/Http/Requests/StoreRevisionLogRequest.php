<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRevisionLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageReviews', \App\Models\RevisionLog::class);
    }

    public function rules(): array
    {
        return [
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'session_id' => ['required', 'integer', 'exists:sessions,id'],
            'hizb_from' => ['nullable', 'numeric', 'between:1,60'],
            'hizb_to' => ['nullable', 'numeric', 'between:1,60'],
            'murajaa_score' => ['nullable', 'numeric', 'min:0', 'max:20'],
        ];
    }
}
