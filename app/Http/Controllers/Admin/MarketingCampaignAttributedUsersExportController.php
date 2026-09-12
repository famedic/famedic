<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MarketingCampaigns\MarketingCampaignAttributedUsersRequest;
use App\Models\MarketingCampaign;
use App\Services\Marketing\MarketingCampaignAttributedUsersPresenter;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MarketingCampaignAttributedUsersExportController extends Controller
{
    public function __invoke(
        MarketingCampaignAttributedUsersRequest $request,
        MarketingCampaign $marketingCampaign,
        MarketingCampaignAttributedUsersPresenter $presenter,
    ): StreamedResponse {
        $includePii = $request->user()?->can('viewAttributedUserPii', $marketingCampaign) ?? false;
        $filters = $request->attributedUserFilters();
        $rows = $presenter->exportRows($marketingCampaign, $filters, $includePii);

        Log::info('marketing_campaign_attributed_users_csv_exported', [
            'campaign_id' => $marketingCampaign->id,
            'administrator_id' => $request->user()?->administrator?->id,
            'user_id' => $request->user()?->id,
            'filters' => $filters,
            'rows_count' => count($rows),
            'includes_pii' => $includePii,
        ]);

        $filename = 'usuarios-atribuidos-campana-'.$marketingCampaign->id.'-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows, $includePii) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $this->headings($includePii));

            foreach ($rows as $row) {
                fputcsv($handle, array_map(
                    fn ($heading) => $row[$heading] ?? '',
                    $this->headings($includePii),
                ));
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * @return list<string>
     */
    private function headings(bool $includePii): array
    {
        $headings = [
            'referencia_atribucion',
            'identificado_en',
        ];

        if ($includePii) {
            $headings[] = 'nombre';
            $headings[] = 'correo';
        }

        return [
            ...$headings,
            'estado',
            'campana_registro',
            'enlace_registro',
            'slug_registro',
            'utm_source',
            'utm_medium',
            'utm_campaign',
            'utm_term',
            'utm_content',
            'campana_first_touch',
            'enlace_first_touch',
            'campana_last_touch',
            'enlace_last_touch',
            'conversiones_total',
            'revenue_total_centavos',
            'conversiones_first_touch',
            'revenue_first_touch_centavos',
            'conversiones_last_touch',
            'revenue_last_touch_centavos',
            'ultima_conversion_en',
        ];
    }
}
