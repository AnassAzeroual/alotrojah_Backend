<?php

namespace App\Http\Requests;

use App\Enums\Audience;
use App\Models\Announcement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAnnouncementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Announcement::class);
    }

    public function rules(): array
    {
        return [
            'audience' => ['required', Rule::enum(Audience::class)],
            'group_id' => ['required', 'integer', 'exists:groups,id'],
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string'],
        ];
    }
}
