<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMessageRequest extends FormRequest
{
    /**
     * Maximum attachment size in kilobytes (100 MB).
     */
    public const MAX_FILE_KB = 102400;

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
            'body' => ['nullable', 'string', 'max:4096', 'required_without:file'],
            'file' => ['nullable', 'file', 'max:'.self::MAX_FILE_KB],
            'reply_to_id' => ['nullable', 'integer', Rule::exists('messages', 'id')],
        ];
    }
}
