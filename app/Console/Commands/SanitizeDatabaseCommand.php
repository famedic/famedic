<?php

namespace App\Console\Commands;

use App\Actions\GeneratePhoneNumberAction;
use App\Enums\Gender;
use App\Models\Customer;
use App\Models\OnlinePharmacyCartItem;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SanitizeDatabaseCommand extends Command
{
    protected $signature = 'db:sanitise {--keep-pii} {--dry-run}';

    protected $description = 'Sanitise PII and Stripe IDs in the local database.';

    public function handle()
    {
        if (app()->environment('production')) {
            $this->error('This command cannot be run in production.');

            return 1;
        }
        $summary = [];
        DB::transaction(function () use (&$summary) {
            $summary['users'] = 0;

            if (! $this->option('keep-pii')) {
                $this->info('Sanitising and anonymizing users...');
                User::chunk(100, function ($users) use (&$summary) {
                    foreach ($users as $user) {
                        if (! $this->option('dry-run')) {
                            $updateData = [];

                            if (! empty($user->name)) {
                                $updateData['name'] = fake()->name();
                            }
                            if (! empty($user->paternal_lastname)) {
                                $updateData['paternal_lastname'] = fake()->lastName();
                            }
                            if (! empty($user->maternal_lastname)) {
                                $updateData['maternal_lastname'] = fake()->lastName();
                            }
                            if (! empty($user->email)) {
                                $updateData['email'] = fake()->unique()->safeEmail();
                            }
                            if (! empty($user->phone)) {
                                $phone = app(GeneratePhoneNumberAction::class)();
                                $updateData['phone'] = str_replace(' ', '', $phone->formatNational());
                                $updateData['phone_country'] = $phone->getCountry();
                            }
                            if (! empty($user->birth_date)) {
                                $updateData['birth_date'] = fake()->date();
                            }
                            if (! empty($user->gender)) {
                                $updateData['gender'] = fake()->randomElement(Gender::cases())->value;
                            }

                            if (! empty($updateData)) {
                                $user->update($updateData);
                            }
                        }
                        $summary['users']++;
                    }
                });
            }

            if (! $this->option('dry-run')) {
                Customer::whereNotNull('stripe_id')->update(['stripe_id' => null]);
            }

            // Clear Online Pharmacy carts to avoid invalid product IDs from production copies
            $this->info('Clearing online pharmacy carts...');
            if (! $this->option('dry-run')) {
                OnlinePharmacyCartItem::query()->delete();
            }
        }, 3);

        $this->info('Sanitisation complete!');

        return 0;
    }
}
