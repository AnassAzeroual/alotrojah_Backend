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
            // §D: only my_students announcements need a section — center/global
            // posts (all/teachers/manager) are group-less.
            'group_id' => [
                'nullable', 'integer', 'exists:groups,id',
                Rule::requiredIf(fn () => $this->input('audience') === 'my_students'),
            ],
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string'],
        ];
    }
}
