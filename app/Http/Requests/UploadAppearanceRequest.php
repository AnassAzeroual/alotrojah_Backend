<?php

namespace App\Http\Requests;

use App\Models\AppearanceSetting;
use Illuminate\Foundation\Http\FormRequest;

class UploadAppearanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', AppearanceSetting::class);
    }

    public function rules(): array
    {
        // 2 MB matches this host's upload_max_filesize; SVG stays rejected
        // (it would run scripts when loaded as a CSS background).
        return [
            'center_id' => ['sometimes', 'integer', 'exists:centers,id'],
            'image' => ['required', 'file', 'image', 'mimetypes:image/jpeg,image/png,image/webp', 'max:2048'],
        ];
    }
}
