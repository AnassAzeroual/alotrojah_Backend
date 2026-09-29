<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CenterSeasonRequest extends FormRequest
{
    public function authorize(): bool
    {
        $me = $this->user();

        return $me->role === 'admin' || (int) $this->input('center_id') === (int) $me->center_id;
    }

    public function rules(): array
    {
        return [
            'center_id' => ['required', 'integer', 'exists:centers,id'],
            'season_id' => ['required', 'integer', 'exists:academic_seasons,id'],
        ];
    }
}
