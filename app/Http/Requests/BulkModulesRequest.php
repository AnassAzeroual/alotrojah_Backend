<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkModulesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', \App\Models\ScoringModule::class);
    }

    public function rules(): array
    {
        return [
            'center_id' => ['sometimes', 'nullable', 'integer', 'exists:centers,id'],
            'modules' => ['required', 'array', 'min:1', 'max:50'],
            'modules.*.code' => ['required', 'string', 'exists:scoring_modules,code'],
            'modules.*.max_points' => ['sometimes', 'numeric', 'min:0', 'max:20'],
            'modules.*.is_active' => ['sometimes', 'boolean'],
            'modules.*.is_in_weekly_total' => ['sometimes', 'boolean'],
            'modules.*.sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
