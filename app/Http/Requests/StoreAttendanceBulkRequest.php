<?php

namespace App\Http\Requests;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAttendanceBulkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', Attendance::class);
    }

    public function rules(): array
    {
        return [
            'session_id' => ['required', 'integer', 'exists:sessions,id'],
            'records' => ['required', 'array', 'min:1', 'max:200'],
            'records.*.student_id' => ['required', 'integer', 'exists:students,id'],
            'records.*.status' => ['required', Rule::enum(AttendanceStatus::class)],
            'records.*.notes' => ['nullable', 'string', 'max:255'],
        ];
    }
}
