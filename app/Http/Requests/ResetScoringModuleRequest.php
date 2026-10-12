<?php

namespace App\Http\Requests;

use App\Models\ScoringModule;
use Illuminate\Foundation\Http\FormRequest;

class ResetScoringModuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', ScoringModule::class);
    }

    public function rules(): array
    {
        return [
            'center_id' => ['required', 'integer', 'exists:centers,id'],
        ];
    }
}
