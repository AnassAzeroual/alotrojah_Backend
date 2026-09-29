<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpsertWeeklyGoalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', \App\Models\WeeklyGoal::class);
    }

    public function rules(): array
    {
        return [
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'week_id' => ['required', 'integer', 'exists:weeks,id'],
            'target_text' => ['nullable', 'string', 'max:300'],
            'is_completed' => ['nullable', 'boolean'],
        ];
    }
}
