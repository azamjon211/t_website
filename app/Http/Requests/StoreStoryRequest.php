<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreStoryRequest extends FormRequest
{
    /**
     * Maximum story size in kilobytes (50 MB).
     */
    public const MAX_FILE_KB = 51200;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/gif,image/webp,video/mp4,video/webm,video/quicktime', 'max:'.self::MAX_FILE_KB],
            'caption' => ['nullable', 'string', 'max:200'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.mimetypes' => 'Story uchun faqat rasm (jpg, png, gif, webp) yoki video (mp4, webm, mov) mumkin.',
        ];
    }
}
