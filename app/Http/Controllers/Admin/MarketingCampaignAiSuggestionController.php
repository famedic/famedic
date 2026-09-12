<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\MarketingCampaigns\SuggestMarketingCampaignCollectionAction;
use App\Actions\Admin\MarketingCampaigns\SuggestMarketingCampaignLandingContentAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MarketingCampaigns\SuggestMarketingCampaignCollectionRequest;
use App\Http\Requests\Admin\MarketingCampaigns\SuggestMarketingCampaignLandingContentRequest;
use Illuminate\Http\JsonResponse;
use RuntimeException;
use Illuminate\Validation\ValidationException;

class MarketingCampaignAiSuggestionController extends Controller
{
    public function landingContent(
        SuggestMarketingCampaignLandingContentRequest $request,
        SuggestMarketingCampaignLandingContentAction $action,
    ): JsonResponse {
        try {
            return response()->json([
                'success' => true,
                'data' => $action($request->validated()),
            ]);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'AI_SUGGESTION_FAILED',
                    'message' => str_contains($exception->getMessage(), 'API key')
                        ? 'No fue posible generar sugerencias. Revisa la configuración de IA.'
                        : 'No fue posible generar sugerencias.',
                ],
            ], 422);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'AI_SUGGESTION_FAILED',
                    'message' => 'No fue posible generar sugerencias.',
                ],
            ], 422);
        }
    }

    public function collection(
        SuggestMarketingCampaignCollectionRequest $request,
        SuggestMarketingCampaignCollectionAction $action,
    ): JsonResponse {
        try {
            return response()->json([
                'success' => true,
                'data' => $action($request->validated()),
            ]);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'AI_SUGGESTION_FAILED',
                    'message' => str_contains($exception->getMessage(), 'API key')
                        ? 'No fue posible generar sugerencias. Revisa la configuración de IA.'
                        : 'No fue posible generar sugerencias.',
                ],
            ], 422);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'AI_SUGGESTION_FAILED',
                    'message' => 'No fue posible generar sugerencias.',
                ],
            ], 422);
        }
    }
}
