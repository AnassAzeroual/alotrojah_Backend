<?php

namespace App\Http\Requests;

use App\Enums\NotificationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateNotificationStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('notification'));
    }

    public function rules(): array
    {
        return ['status' => ['required', Rule::enum(NotificationStatus::class)]];
    }
}
