<?php

namespace App\Http\Requests\Admin\MarketingCampaigns;

use App\Models\MarketingCampaign;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class ShowMarketingCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        $campaign = $this->route('marketing_campaign');

        return $campaign instanceof MarketingCampaign
            && ($this->user()?->can('view', $campaign) ?? false);
    }

    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'link_id' => [
                'nullable',
                'integer',
                Rule::exists('marketing_campaign_links', 'id')
                    ->where('marketing_campaign_id', $this->route('marketing_campaign')?->id),
            ],
            'utm_source' => ['nullable', 'string', 'max:255'],
            'utm_medium' => ['nullable', 'string', 'max:255'],
            'attributed_from' => ['nullable', 'date'],
            'attributed_to' => ['nullable', 'date', 'after_or_equal:attributed_from'],
            'attributed_link_id' => [
                'nullable',
                'integer',
                Rule::exists('marketing_campaign_links', 'id')
                    ->where('marketing_campaign_id', $this->route('marketing_campaign')?->id),
            ],
            'attributed_utm_source' => ['nullable', 'string', 'max:255'],
            'attributed_utm_medium' => ['nullable', 'string', 'max:255'],
            'attributed_status' => ['nullable', Rule::in(['registered', 'buyer', 'registered_without_purchase'])],
            'attributed_page' => ['nullable', 'integer', 'min:1'],
            'attributed_per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $from = $this->input('from');
            $to = $this->input('to');

            if ($from && $to
                && ! $validator->errors()->has('from')
                && ! $validator->errors()->has('to')
                && Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 366
            ) {
                $validator->errors()->add('to', 'El rango máximo permitido es de 366 días.');
            }

            $attributedFrom = $this->input('attributed_from');
            $attributedTo = $this->input('attributed_to');

            if ($attributedFrom && $attributedTo
                && ! $validator->errors()->has('attributed_from')
                && ! $validator->errors()->has('attributed_to')
                && Carbon::parse($attributedFrom)->diffInDays(Carbon::parse($attributedTo)) > 366
            ) {
                $validator->errors()->add('attributed_to', 'El rango máximo permitido es de 366 días.');
            }
        });
    }

    /**
     * @return array{from: string, to: string, link_id: string, utm_source: string, utm_medium: string}
     */
    public function dashboardFilters(): array
    {
        $validated = $this->validated();

        return [
            'from' => (string) ($validated['from'] ?? ''),
            'to' => (string) ($validated['to'] ?? ''),
            'link_id' => (string) ($validated['link_id'] ?? ''),
            'utm_source' => (string) ($validated['utm_source'] ?? ''),
            'utm_medium' => (string) ($validated['utm_medium'] ?? ''),
        ];
    }

    /**
     * @return array{from: string, to: string, link_id: string, utm_source: string, utm_medium: string, status: string, per_page: int}
     */
    public function attributedUserFilters(): array
    {
        $validated = $this->validated();

        return [
            'from' => (string) ($validated['attributed_from'] ?? ''),
            'to' => (string) ($validated['attributed_to'] ?? ''),
            'link_id' => (string) ($validated['attributed_link_id'] ?? ''),
            'utm_source' => (string) ($validated['attributed_utm_source'] ?? ''),
            'utm_medium' => (string) ($validated['attributed_utm_medium'] ?? ''),
            'status' => (string) ($validated['attributed_status'] ?? ''),
            'per_page' => (int) ($validated['attributed_per_page'] ?? 25),
        ];
    }
}
