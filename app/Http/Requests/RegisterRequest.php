<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Usernames are case-insensitive: "@Ali" and "ali" are the same account.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'username' => strtolower(ltrim(trim((string) $this->input('username')), '@')),
            'name' => trim((string) $this->input('name')),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:64'],
            'username' => ['required', 'string', 'min:3', 'max:32', 'regex:/^[a-z][a-z0-9_]*$/', 'unique:users,username'],
            'password' => ['required', 'confirmed', Password::min(6)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'username.unique' => 'Bu username band. Boshqasini tanlang.',
            'username.regex' => 'Username lotin harfi bilan boshlanib, faqat a-z, 0-9 va _ dan iborat bo\'lishi kerak.',
        ];
    }
}
