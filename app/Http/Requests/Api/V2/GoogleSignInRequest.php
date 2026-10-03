<?php

namespace App\Http\Requests\Api\V2;

use Illuminate\Foundation\Http\FormRequest;

class GoogleSignInRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * An ID TOKEN, not an access token. An access token can be lifted from a
     * different Google application and still return that person's profile, so it
     * proves nothing about who it was issued for. An ID token carries its
     * audience, which is what GoogleIdTokenVerifier checks.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'id_token' => ['required', 'string', 'max:4096'],
            'referral' => ['nullable', 'string', 'max:64'],
        ];
    }
}
