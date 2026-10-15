<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CallSignalRequest extends FormRequest
{
    /**
     * Signals of a WebRTC call: the caller invites with an SDP offer, the callee accepts with an answer.
     *
     * @var list<string>
     */
    public const TYPES = ['invite', 'accept', 'reject', 'busy', 'cancel', 'end'];

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
            'type' => ['required', 'string', Rule::in(self::TYPES)],
            'call_id' => ['required', 'uuid'],
            'media' => ['required_if:type,invite', 'nullable', Rule::in(['audio', 'video'])],
            'sdp' => ['required_if:type,invite,accept', 'nullable', 'string', 'max:30000'],
        ];
    }
}
