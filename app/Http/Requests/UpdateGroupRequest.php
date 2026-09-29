<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('group'));
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'level_id' => ['sometimes', 'integer', 'exists:levels,id'],
            'teacher_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'academic_year' => ['sometimes', 'nullable', 'string', 'max:20'],
            'capacity' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:500'],
            'schedule_days' => ['sometimes', 'string', 'max:60'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
