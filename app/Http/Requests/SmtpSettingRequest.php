<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates an SMTP profile. The password is required when creating and
 * optional when editing — blank means "keep the stored one".
 */
class SmtpSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name'         => ['required', 'string', 'max:255'],
            'host'         => ['required', 'string', 'max:255'],
            'port'         => ['required', 'integer', 'min:1', 'max:65535'],
            'username'     => ['required', 'string', 'max:255'],
            'password'     => [$this->isMethod('post') ? 'required' : 'nullable', 'string'],
            'encryption'   => ['nullable', Rule::in(['tls', 'ssl'])],
            'from_address' => ['required', 'email', 'max:255'],
            'from_name'    => ['required', 'string', 'max:255'],
        ];
    }
}
