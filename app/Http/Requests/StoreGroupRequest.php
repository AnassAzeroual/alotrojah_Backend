<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', \App\Models\Group::class);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'center_id' => ['required', 'integer', 'exists:centers,id'],
            'level_id' => ['required', 'integer', 'exists:levels,id'],
            'teacher_id' => ['nullable', 'integer', 'exists:users,id'],
            'academic_year' => ['nullable', 'string', 'max:20'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:500'],
            'schedule_days' => ['sometimes', 'string', 'max:60'],
        ];
    }
}
