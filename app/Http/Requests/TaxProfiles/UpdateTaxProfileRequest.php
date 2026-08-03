<?php

namespace App\Http\Requests\TaxProfiles;

use App\Exceptions\TaxProfiles\ConstanciaExtractionException;
use App\Rules\IndividualRfc;
use App\Services\TaxProfiles\IndividualTaxpayerValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTaxProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('tax_profile'));
    }

    public function rules(): array
    {
        $cfdiUses = array_keys(config('taxregimes.uses', []));

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'rfc' => ['required', 'string', new IndividualRfc],
            'zipcode' => ['required', 'string', 'size:5'],
            'tax_regime' => ['required', 'string'],
            'cfdi_use' => ['required', 'string', Rule::in($cfdiUses)],
            'extracted_data' => ['nullable'],
            'confirm_data' => ['sometimes', 'boolean'],
            'tipo_persona' => ['sometimes', 'nullable', 'string'],
        ];

        if ($this->hasFile('fiscal_certificate')) {
            $rules['fiscal_certificate'] = ['file', 'mimes:pdf', 'max:5120'];
        } else {
            $rules['fiscal_certificate'] = ['sometimes', 'nullable'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'cfdi_use.required' => 'El uso de CFDI es obligatorio.',
            'cfdi_use.in' => 'El uso de CFDI seleccionado no es válido.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            /** @var IndividualTaxpayerValidator $taxpayerValidator */
            $taxpayerValidator = app(IndividualTaxpayerValidator::class);

            $extracted = $this->decodedExtractedData();
            $tipoPersona = $this->input('tipo_persona')
                ?? ($extracted['tipo_persona'] ?? null);

            try {
                $taxpayerValidator->assertIndividualForPersistence(
                    (string) $this->input('rfc', ''),
                    is_string($tipoPersona) ? $tipoPersona : null,
                );
            } catch (ConstanciaExtractionException $e) {
                $validator->errors()->add('rfc', $e->publicMessage());
            } catch (\InvalidArgumentException $e) {
                $validator->errors()->add('rfc', $e->getMessage());
            }

            if (is_array($extracted) && (
                ($extracted['taxpayer_type'] ?? null) === 'legal_entity'
                || ($extracted['tipo_persona'] ?? null) === 'moral'
            )) {
                $validator->errors()->add(
                    'rfc',
                    ConstanciaExtractionException::legalEntityNotAllowed()->publicMessage()
                );
            }
        });
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('rfc')) {
            $this->merge([
                'rfc' => strtoupper(trim((string) $this->input('rfc'))),
            ]);
        }

        if ($this->has('extracted_data') && is_string($this->input('extracted_data'))) {
            $decoded = json_decode($this->input('extracted_data'), true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $this->merge(['extracted_data' => $decoded]);
            }
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function decodedExtractedData(): ?array
    {
        $extracted = $this->input('extracted_data');

        return is_array($extracted) ? $extracted : null;
    }
}
