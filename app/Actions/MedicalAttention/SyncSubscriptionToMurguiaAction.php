<?php

namespace App\Actions\MedicalAttention;

use App\Enums\MedicalSubscriptionType;
use App\Models\CertificateAccount;
use App\Models\MedicalAttentionSubscription;
use App\Models\OdessaAfiliateAccount;
use App\Models\OdessaAfiliatedCompany;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class SyncSubscriptionToMurguiaAction
{
    private CheckStatusAction $checkStatusAction;
    private RegisterAction $registerAction;
    private UpdateStatusAction $updateStatusAction;

    public function __construct(
        CheckStatusAction $checkStatusAction,
        RegisterAction $registerAction,
        UpdateStatusAction $updateStatusAction
    ) {
        $this->checkStatusAction = $checkStatusAction;
        $this->registerAction = $registerAction;
        $this->updateStatusAction = $updateStatusAction;
    }

    public function __invoke(
        MedicalAttentionSubscription $subscription,
        string $status,
        Carbon $startDate,
        Carbon $endDate
    ): bool
    {
        $customer = $subscription->customer;

        try {
            $checkResponse = ($this->checkStatusAction)($customer);
            $responseData = $checkResponse->json();
            $customerExists = isset($responseData['success']) && $responseData['success'] === true;

            $producto = $this->getProducto($subscription);
            $subProducto = $this->getSubProducto($subscription);
            
            if ($customerExists) {
                $syncResponse = ($this->updateStatusAction)(
                    $customer, $startDate, $endDate, $status, $producto, $subProducto
                );
                $action = 'update';
            } else {
                $syncResponse = ($this->registerAction)(
                    $customer, $startDate, $endDate, $producto, $subProducto
                );
                $action = 'register';
            }

            if ($syncResponse->successful()) {
                $subscription->update(['synced_with_murguia_at' => now()]);
                return true;
            } else {
                Log::error("Murguia {$action} failed", [
                    'subscription_id' => $subscription->id,
                    'status' => $syncResponse->status(),
                    'response' => $syncResponse->json(),
                ]);
                return false;
            }

        } catch (\Exception $e) {
            Log::error('Murguia sync exception', [
                'subscription_id' => $subscription->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function getProducto(MedicalAttentionSubscription $subscription): string
    {
        return match ($subscription->type) {
            MedicalSubscriptionType::TRIAL => 'Prueba Gratuita',
            MedicalSubscriptionType::REGULAR => 'Regular',
            MedicalSubscriptionType::INSTITUTIONAL => 'Institucional ODESSA',
            MedicalSubscriptionType::FAMILY_MEMBER => 'Miembro Familiar',
            default => 'Regular',
        };
    }

    private function getSubProducto(MedicalAttentionSubscription $subscription): string
    {
        // For family members, return the parent's medical_attention_identifier as JSON
        if ($subscription->type === MedicalSubscriptionType::FAMILY_MEMBER && $subscription->parentSubscription) {
            $parentCustomer = $subscription->parentSubscription->customer;
            return json_encode([
                'parent_identifier' => $parentCustomer->medical_attention_identifier,
            ]);
        }

        // For institutional subscriptions, check if it's an Odessa affiliate or Certificate account
        if ($subscription->type === MedicalSubscriptionType::INSTITUTIONAL) {
            $customer = $subscription->customer;
            
            // Handle Odessa affiliate accounts
            if ($customer->customerable_type === OdessaAfiliateAccount::class && $customer->customerable) {
                $odessaAccount = $customer->customerable->load('odessaAfiliatedCompany');
                $metadata = [
                    'partner_identifier' => $odessaAccount->partner_identifier,
                    'is_odessa_company' => true,
                ];
                
                if ($odessaAccount->odessaAfiliatedCompany) {
                    $metadata['company_identifier'] = $odessaAccount->odessaAfiliatedCompany->odessa_identifier;
                    
                    // Include company name if available
                    if ($odessaAccount->odessaAfiliatedCompany->name) {
                        $metadata['company_name'] = $odessaAccount->odessaAfiliatedCompany->name;
                    }
                }
                
                return json_encode($metadata);
            }
            
            // Handle Certificate accounts
            if ($customer->customerable_type === CertificateAccount::class && $customer->customerable) {
                $certificateAccount = $customer->customerable->load('companyable');
                $metadata = [
                    'is_odessa_company' => false,
                ];
                
                // Add employee metadata if available
                if ($certificateAccount->employee_metadata) {
                    $metadata = array_merge($metadata, $certificateAccount->employee_metadata);
                }
                
                // Add company information
                if ($certificateAccount->companyable) {
                    $metadata['company_name'] = $certificateAccount->companyable->name;
                    
                    // For Odessa companies, add the odessa_identifier
                    if ($certificateAccount->companyable_type === OdessaAfiliatedCompany::class) {
                        $metadata['is_odessa_company'] = true;
                        $metadata['company_identifier'] = $certificateAccount->companyable->odessa_identifier;
                    }
                }
                
                return json_encode($metadata);
            }
        }

        // Default: return empty JSON object for consistency
        return json_encode([]);
    }
}