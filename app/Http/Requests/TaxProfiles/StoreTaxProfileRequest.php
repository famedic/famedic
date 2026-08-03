<?php

namespace App\Http\Requests\TaxProfiles;

use App\Exceptions\TaxProfiles\ConstanciaExtractionException;
use App\Rules\IndividualRfc;
use App\Services\TaxProfiles\IndividualTaxpayerValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTaxProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->customer !== null;
    }

    public function rules(): array
    {
        $customerId = $this->user()->customer->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'rfc' => [
                'required',
                'string',
                new IndividualRfc,
                Rule::unique('tax_profiles', 'rfc')->where(function ($query) use ($customerId) {
                    return $query->where('customer_id', $customerId)->whereNull('deleted_at');
                }),
            ],
            'zipcode' => ['required', 'string', 'regex:/^\d{5}$/'],
            'tax_regime' => ['required', 'string'],
            'cfdi_use' => ['sometimes', 'nullable', 'string', Rule::in(array_keys(config('taxregimes.uses', [])))],
            'fiscal_certificate' => ['required', 'file', 'mimes:pdf', 'max:5120'],
            'confirm_data' => ['sometimes', 'boolean'],
            'extracted_data' => ['sometimes', 'nullable'],
            'tipo_persona' => ['sometimes', 'nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'rfc.required' => 'El RFC es obligatorio.',
            'rfc.unique' => 'Ya tienes un perfil fiscal registrado con este RFC.',
            'zipcode.required' => 'El código postal es obligatorio.',
            'zipcode.regex' => 'El código postal debe tener exactamente 5 dígitos.',
            'tax_regime.required' => 'El régimen fiscal es obligatorio.',
            'fiscal_certificate.required' => 'La constancia fiscal es obligatoria.',
            'fiscal_certificate.file' => 'El archivo de constancia fiscal debe ser un archivo válido.',
            'fiscal_certificate.mimes' => 'La constancia fiscal debe ser un archivo PDF.',
            'fiscal_certificate.max' => 'La constancia fiscal no debe exceder 5MB.',
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

            if (is_array($extracted)) {
                $extractedRfc = $extracted['rfc'] ?? null;
                if (is_string($extractedRfc) && $extractedRfc !== '') {
                    try {
                        $taxpayerValidator->assertIndividualForPersistence(
                            $extractedRfc,
                            is_string($extracted['tipo_persona'] ?? null) ? $extracted['tipo_persona'] : null,
                        );
                    } catch (ConstanciaExtractionException $e) {
                        $validator->errors()->add('rfc', $e->publicMessage());
                    } catch (\InvalidArgumentException $e) {
                        $validator->errors()->add('rfc', $e->getMessage());
                    }
                }

                if (($extracted['taxpayer_type'] ?? null) === 'legal_entity'
                    || ($extracted['tipo_persona'] ?? null) === 'moral') {
                    $validator->errors()->add(
                        'rfc',
                        ConstanciaExtractionException::legalEntityNotAllowed()->publicMessage()
                    );
                }
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
