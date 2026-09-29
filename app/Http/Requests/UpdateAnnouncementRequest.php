<?php

namespace App\Http\Requests;

use App\Enums\Audience;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAnnouncementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('announcement'));
    }

    public function rules(): array
    {
        return [
            'audience' => ['sometimes', Rule::enum(Audience::class)],
            'group_id' => ['sometimes', 'nullable', 'integer', 'exists:groups,id'],
            'title' => ['sometimes', 'string', 'max:200'],
            'body' => ['sometimes', 'string'],
        ];
    }
}
