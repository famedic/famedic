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
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $from = $this->input('from');
            $to = $this->input('to');

            if (! $from || ! $to || $validator->errors()->has('from') || $validator->errors()->has('to')) {
                return;
            }

            if (Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 366) {
                $validator->errors()->add('to', 'El rango máximo permitido es de 366 días.');
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
}
