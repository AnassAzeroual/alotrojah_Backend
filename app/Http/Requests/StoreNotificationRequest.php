<?php

namespace App\Http\Requests;

use App\Enums\NotificationChannel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', \App\Models\NotificationLog::class);
    }

    public function rules(): array
    {
        return [
            'recipient_phone' => ['required', 'string', 'max:30', 'regex:/^[+\d][\d\s]+$/'],
            'message' => ['required', 'string', 'max:2000'],
            'channel' => ['sometimes', Rule::enum(NotificationChannel::class)],
        ];
    }
}
