<?php

namespace Database\Seeders;

use App\Models\Setting;
use App\Models\SettingGroup;
use Illuminate\Database\Seeder;

class ConfigMonitorSeeder extends Seeder
{
    public function run(): void
    {
        $groups = [
            ['name' => 'GDA', 'slug' => 'gda', 'sort_order' => 10],
            ['name' => 'PAYMENTS', 'slug' => 'payments', 'sort_order' => 20],
            ['name' => 'LAB', 'slug' => 'lab', 'sort_order' => 30],
            ['name' => 'PHARMACY', 'slug' => 'pharmacy', 'sort_order' => 40],
            ['name' => 'AWS', 'slug' => 'aws', 'sort_order' => 50],
            ['name' => 'MAIL', 'slug' => 'mail', 'sort_order' => 60],
        ];

        foreach ($groups as $g) {
            SettingGroup::query()->firstOrCreate(
                ['slug' => $g['slug']],
                ['name' => $g['name'], 'sort_order' => $g['sort_order']]
            );
        }

        $gda = SettingGroup::query()->where('slug', 'gda')->first();
        $payments = SettingGroup::query()->where('slug', 'payments')->first();
        $lab = SettingGroup::query()->where('slug', 'lab')->first();
        $pharmacy = SettingGroup::query()->where('slug', 'pharmacy')->first();
        $aws = SettingGroup::query()->where('slug', 'aws')->first();
        $mail = SettingGroup::query()->where('slug', 'mail')->first();

        $defaults = [
            [
                'group' => $gda,
                'env_key' => 'GDA_URL',
                'config_key' => 'services.gda.url',
                'label' => 'URL API GDA',
                'is_sensitive' => false,
                'is_required' => true,
                'sort_order' => 10,
            ],
            [
                'group' => $payments,
                'env_key' => 'PAYPAL_MODE',
                'config_key' => 'services.paypal.mode',
                'label' => 'Modo PayPal',
                'is_sensitive' => false,
                'is_required' => false,
                'sort_order' => 10,
            ],
            [
                'group' => $lab,
                'env_key' => 'LABORATORY_PURCHASE_PDFS_PATH',
                'config_key' => 'famedic.storage_paths.laboratory_purchase_pdfs',
                'label' => 'Ruta PDFs laboratorio',
                'is_sensitive' => false,
                'is_required' => false,
                'sort_order' => 10,
            ],
            [
                'group' => $pharmacy,
                'env_key' => 'VITAU_URL',
                'config_key' => 'services.vitau.url',
                'label' => 'URL Vitau',
                'is_sensitive' => false,
                'is_required' => true,
                'sort_order' => 10,
            ],
            [
                'group' => $aws,
                'env_key' => 'AWS_DEFAULT_REGION',
                'config_key' => 'filesystems.disks.s3.region',
                'label' => 'Región S3',
                'is_sensitive' => false,
                'is_required' => false,
                'sort_order' => 10,
            ],
            [
                'group' => $mail,
                'env_key' => 'MAIL_MAILER',
                'config_key' => 'mail.default',
                'label' => 'Mailer por defecto',
                'is_sensitive' => false,
                'is_required' => true,
                'sort_order' => 10,
            ],
        ];

        foreach ($defaults as $row) {
            if (! $row['group']) {
                continue;
            }
            Setting::query()->firstOrCreate(
                ['env_key' => $row['env_key']],
                [
                    'setting_group_id' => $row['group']->id,
                    'config_key' => $row['config_key'],
                    'label' => $row['label'],
                    'is_sensitive' => $row['is_sensitive'],
                    'is_required' => $row['is_required'],
                    'sort_order' => $row['sort_order'],
                ]
            );
        }
    }
}
