<?php

use App\Models\CertificateAccount;
use App\Models\OdessaAfiliatedCompany;
use App\Models\Customer;
use App\Models\MedicalAttentionSubscription;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Log::info('Starting pregenerated certificates migration...');

        $totalProcessed = 0;
        $companiesCreated = 0;
        $customersSkipped = 0;
        $skippedCustomerDetails = [];

        DB::connection('mysqlold')
            ->table('pregenerated_medical_attention_ids')
            ->orderBy('id')
            ->chunk(100, function ($certificates) use (&$totalProcessed, &$companiesCreated, &$customersSkipped, &$skippedCustomerDetails) {
                foreach ($certificates as $certificate) {
                    // Check if company exists by company_number
                    $company = OdessaAfiliatedCompany::where('odessa_identifier', $certificate->company_number)->first();

                    if (!$company) {
                        // Create company if it doesn't exist
                        $company = OdessaAfiliatedCompany::create([
                            'odessa_identifier' => $certificate->company_number,
                        ]);
                        $companiesCreated++;
                    }

                    // Create CertificateAccount
                    $certificateAccount = CertificateAccount::create([
                        'name' => $certificate->name,
                        'employee_metadata' => [
                            'employee_number' => $certificate->employee_number,
                            'odessa_identifier' => $certificate->medical_attention_id,
                        ],
                        'companyable_type' => OdessaAfiliatedCompany::class,
                        'companyable_id' => $company->id,
                        'created_at' => $certificate->created_at,
                        'updated_at' => $certificate->updated_at,
                    ]);

                    // Check if customer with this medical_attention_identifier already exists
                    $existingCustomer = Customer::where('medical_attention_identifier', $certificate->medical_attention_id)->first();

                    if (!$existingCustomer) {
                        // Create Customer for this certificate
                        Customer::create([
                            'medical_attention_identifier' => $certificate->medical_attention_id,
                            'customerable_type' => CertificateAccount::class,
                            'customerable_id' => $certificateAccount->id,
                            'created_at' => $certificate->created_at,
                            'updated_at' => $certificate->updated_at,
                        ]);
                    } else {
                        // Customer already exists, skip creation
                        $customersSkipped++;

                        // Load relationships based on customer type
                        if ($existingCustomer->customerable_type === 'App\Models\OdessaAfiliateAccount') {
                            $existingCustomer->load(['customerable.odessaAfiliatedCompany', 'medicalAttentionSubscriptions']);
                        } else {
                            $existingCustomer->load(['customerable', 'medicalAttentionSubscriptions']);
                        }

                        $customerData = [
                            'medical_attention_identifier' => $certificate->medical_attention_id,
                            'certificate_name' => $certificate->name,
                            'existing_customer_id' => $existingCustomer->id,
                            'customer_type' => $existingCustomer->customerable_type,
                            'customerable' => $existingCustomer->customerable->toArray(),
                            'subscriptions' => $existingCustomer->medicalAttentionSubscriptions->toArray(),
                        ];

                        $skippedCustomerDetails[] = $customerData;
                    }

                    $totalProcessed++;
                }
            });

        Log::info('Pregenerated certificates migration completed', [
            'total_processed' => $totalProcessed,
            'companies_created' => $companiesCreated,
            'customers_skipped' => $customersSkipped,
        ]);

        // Generate detailed report for skipped customers
        if (!empty($skippedCustomerDetails)) {
            Log::info('=== DETAILED ANALYSIS OF SKIPPED CUSTOMERS ===');

            foreach ($skippedCustomerDetails as $detail) {
                Log::info('Skipped Customer Analysis', $detail);
            }

            // Summary statistics
            $withSubscription = collect($skippedCustomerDetails)->filter(fn($d) => !empty($d['subscriptions']))->count();

            Log::info('=== SUMMARY OF SKIPPED CUSTOMERS ===', [
                'total_skipped' => count($skippedCustomerDetails),
                'with_subscription' => $withSubscription,
                'customer_types' => collect($skippedCustomerDetails)->groupBy('customer_type')->map->count()->toArray(),
            ]);

            // Write detailed JSON report to storage for deeper analysis
            $reportPath = storage_path('logs/certificate_migration_skipped_customers_' . now()->format('Y-m-d_H-i-s') . '.json');
            file_put_contents($reportPath, json_encode($skippedCustomerDetails, JSON_PRETTY_PRINT));
            Log::info('Detailed JSON report saved to: ' . $reportPath);
        }
    }
};
