<?php

namespace App\Http\Requests;

use App\Models\Exam;
use App\Models\Group;
use Illuminate\Foundation\Http\FormRequest;

class WeeklyTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', Exam::class);
    }

    public function rules(): array
    {
        return [
            'group_id' => ['required', 'integer', 'exists:groups,id'],
        ];
    }

    public function group(): Group
    {
        return Group::findOrFail($this->validated()['group_id']);
    }
}
