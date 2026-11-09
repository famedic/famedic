<?php

namespace App\Http\Requests\Api\V1\Integrations;

use App\Http\Requests\Api\V1\ApiFormRequest;
use Illuminate\Contracts\Validation\Validator;

final class AtixPhoneLookupRequest extends ApiFormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        // Validate the signed JSON value, not the whitespace-trimmed value from
        // global middleware. Valid E.164 inputs are already canonical.
        if ($this->isJson()) {
            $original = json_decode($this->getContent(), true);
            if (is_array($original) && array_key_exists('phone', $original)) {
                $this->merge(['phone' => $original['phone']]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'regex:/^\+[1-9][0-9]{7,14}$/D'],
            'conversation_id' => ['required', 'string', 'max:128'],
            'channel_event_id' => ['required', 'string', 'max:128'],
            'channel' => ['required', 'string', 'in:'.implode(',', config('atix.sandbox.allowed_channels', ['whatsapp']))],
            'audience' => ['required', 'string', 'in:'.implode(',', config('atix.sandbox.allowed_audiences', ['leo']))],
            'personalization' => ['required', 'array'],
            'personalization.allowed' => ['required', 'boolean'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $conversation = (string) $this->input('conversation_id', '');
            if ($conversation !== '' && ! preg_match('/^[^\s]{1,128}$/', $conversation)) {
                $validator->errors()->add('conversation_id', 'conversation_id tiene un formato invalido.');
            }
        });
    }
}
