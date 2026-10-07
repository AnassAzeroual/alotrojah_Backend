<?php

namespace App\Http\Requests;

use App\Enums\ModuleScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreModuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', \App\Models\ScoringModule::class);
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:40', Rule::unique('scoring_modules', 'code')->where('center_id', $this->input('center_id')), 'regex:/^[a-z0-9_]+$/'],
            'center_id' => ['sometimes', 'nullable', 'integer', 'exists:centers,id'],
            'name_ar' => ['required', 'string', 'max:120'],
            'max_points' => ['required', 'numeric', 'min:0', 'max:20'],
            'scope' => ['required', Rule::enum(ModuleScope::class)],
            'is_active' => ['sometimes', 'boolean'],
            'is_in_weekly_total' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
