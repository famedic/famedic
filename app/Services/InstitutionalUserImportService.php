<?php

namespace App\Services;

use App\Models\User;
use App\Models\Customer;
use App\Models\CertificateAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

use App\Imports\UsersMedicalAttentionOne;
use App\Imports\UsersMedicalAttentionTwo;
use App\Imports\UsersMedicalAttentionTree;
use App\Imports\UsersMedicalAttentionFour;
use App\Imports\UsersMedicalAttentionFive;

class InstitutionalUserImportService
{
    public function run(int $batch = 1): array
    {
        $users = $this->resolveBatch($batch);

        $chunks = array_chunk($users, 20);

        $results = [
            'batch' => $batch,
            'created' => [],
            'skipped' => [],
            'errors' => [],
        ];

        foreach ($chunks as $chunk) {

            foreach ($chunk as $userData) {

                DB::beginTransaction();

                try {

                    // 🔹 Validar email obligatorio
                    if (empty($userData['email'])) {
                        $results['errors'][] = [
                            'id' => $userData['id'],
                            'message' => 'Email vacío'
                        ];
                        DB::rollBack();
                        continue;
                    }

                    // 🔹 Verificar si ya existe usuario
                    $existingUser = User::where('email', $userData['email'])->first();

                    if ($existingUser) {
                        $results['skipped'][] = "Ya existe usuario: {$userData['email']}";
                        DB::rollBack();
                        continue;
                    }

                    // 🔹 Buscar CertificateAccount por ID
                    $certificate = CertificateAccount::find($userData['id']);

                    if (!$certificate) {
                        $results['errors'][] = [
                            'id' => $userData['id'],
                            'message' => 'CertificateAccount no existe'
                        ];
                        DB::rollBack();
                        continue;
                    }

                    // 🔹 Buscar Customer vinculado
                    $customer = Customer::whereNull('user_id')
                        ->where('customerable_type', CertificateAccount::class)
                        ->where('customerable_id', $certificate->id)
                        ->first();

                    if (!$customer) {
                        $results['errors'][] = [
                            'id' => $userData['id'],
                            'message' => 'Customer no disponible o ya tiene usuario'
                        ];
                        DB::rollBack();
                        continue;
                    }

                    // 🔹 Crear usuario
                    $user = User::create([
                        'name' => $userData['name'],
                        'paternal_lastname' => $userData['paternal_lastname'],
                        'maternal_lastname' => $userData['maternal_lastname'],
                        'email' => $userData['email'],
                        'birth_date' => $userData['birth_date'],
                        'password' => Hash::make('Pa$$word'),
                        'email_verified_at' => now(),
                        'documentation_accepted_at' => now(),
                    ]);

                    // 🔹 Vincular customer
                    $customer->update([
                        'user_id' => $user->id,
                        'medical_attention_subscription_expires_at' => '2027-12-31 23:59:59',
                    ]);

                    DB::commit();

                    $results['created'][] = [
                        'id' => $userData['id'],
                        'email' => $user->email,
                        'identifier' => $customer->medical_attention_identifier,
                        'expires_at' => $customer->medical_attention_subscription_expires_at,
                    ];

                } catch (\Exception $e) {

                    DB::rollBack();

                    $results['errors'][] = [
                        'id' => $userData['id'],
                        'email' => $userData['email'],
                        'message' => $e->getMessage()
                    ];
                }
            }

            unset($chunk);
            gc_collect_cycles();
            usleep(100000);
        }

        return $results;
    }

    private function resolveBatch(int $batch): array
    {
        return match ($batch) {
            1 => UsersMedicalAttentionOne::data(),
            2 => UsersMedicalAttentionTwo::data(),
            3 => UsersMedicalAttentionTree::data(),
            4 => UsersMedicalAttentionFour::data(),
            5 => UsersMedicalAttentionFive::data(),
            default => UsersMedicalAttentionOne::data(),
        };
    }
}
