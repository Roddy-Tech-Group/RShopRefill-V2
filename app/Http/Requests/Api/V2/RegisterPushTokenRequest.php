<?php

namespace App\Http\Requests\Api\V2;

use Illuminate\Foundation\Http\FormRequest;

class RegisterPushTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Expo's tokens look like ExponentPushToken[xxxxxxxxxxxxxxxxxxxxxx] or, for a
     * bare-workflow build, ExpoPushToken[...]. Shape-checked rather than accepted
     * blind: a garbage value would sit in the table forever and every push to it
     * would fail, which is noise we would then have to diagnose.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:255', 'regex:/^Exp(o|onent)PushToken\[[A-Za-z0-9._\-]+\]$/'],
            'platform' => ['nullable', 'string', 'in:ios,android'],
            'app_version' => ['nullable', 'string', 'max:40'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'token.regex' => 'That does not look like a push token.',
        ];
    }
}
