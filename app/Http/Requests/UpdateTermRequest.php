<?php

namespace App\Http\Requests;

use App\Models\AcademicSeason;
use Illuminate\Foundation\Http\FormRequest;

class UpdateTermRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageCalendar', AcademicSeason::class);
    }

    public function rules(): array
    {
        return ['name_ar' => ['sometimes', 'string', 'max:50']];
    }
}
