<?php

namespace Tests\Feature\Marketing;

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Valida up/down de la migración de campañas contra MySQL real en una BD temporal.
 * No toca la base de desarrollo (`famedic`).
 *
 * Requiere acceso MySQL (p. ej. contenedor app con host `mysql`).
 * Se omite automáticamente si MySQL no está disponible.
 */
class MarketingCampaignMysqlMigrationTest extends TestCase
{
    private ?string $tempDatabase = null;

    private string $connection = 'marketing_campaign_mysql_audit';

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = true;
        parent::setUp();

        if (! $this->configureTemporaryMysqlConnection()) {
            $this->markTestSkipped('MySQL temporal no disponible para auditoría de migración.');
        }
    }

    protected function tearDown(): void
    {
        if ($this->tempDatabase !== null) {
            try {
                DB::connection($this->connection)->statement('SET FOREIGN_KEY_CHECKS=0');
                foreach ([
                    'marketing_campaign_visits',
                    'marketing_campaign_attributions',
                    'marketing_campaign_visitor_identities',
                    'marketing_campaign_link_images',
                    'marketing_campaign_link_categories',
                    'marketing_campaign_link_products',
                    'marketing_campaign_collection_items',
                    'marketing_campaign_collections',
                    'marketing_campaign_link_aliases',
                    'marketing_campaign_links',
                    'marketing_campaigns',
                    'laboratory_test_categories',
                    'laboratory_tests',
                    'administrators',
                    'users',
                ] as $table) {
                    Schema::connection($this->connection)->dropIfExists($table);
                }
                DB::connection($this->connection)->statement('SET FOREIGN_KEY_CHECKS=1');
                DB::connection('mysql_root_audit')->statement("DROP DATABASE IF EXISTS `{$this->tempDatabase}`");
            } catch (\Throwable) {
                // best-effort cleanup
            }
        }

        parent::tearDown();
    }

    protected function connectionsToTransact(): array
    {
        return [];
    }

    #[Test]
    public function migracion_marketing_campaign_up_down_y_segunda_ejecucion_en_mysql(): void
    {
        $this->createParentTables();

        $baseMigration = require database_path('migrations/2026_08_06_230000_create_marketing_campaign_tables.php');
        $landingMigration = require database_path('migrations/2026_08_06_230200_add_landing_fields_to_marketing_campaign_links.php');
        $commerceMigration = require database_path('migrations/2026_08_06_230300_add_landing_commerce_to_marketing_campaign_links.php');
        $attributionMigration = require database_path('migrations/2026_08_06_230400_create_marketing_campaign_attribution_tables.php');
        $visitorIdentityMigration = require database_path('migrations/2026_09_11_010000_add_visitor_identities_to_marketing_attribution.php');

        // Primera ejecución: base + landing + commerce + attribution
        $this->runMigrationOnTempConnection($baseMigration, 'up');
        $this->runMigrationOnTempConnection($landingMigration, 'up');
        $this->runMigrationOnTempConnection($commerceMigration, 'up');
        $this->runMigrationOnTempConnection($attributionMigration, 'up');
        $this->seedHistoricalAttributionFixtures();
        $this->runMigrationOnTempConnection($visitorIdentityMigration, 'up');
        $this->assertMarketingTablesExist();
        $this->assertNamedConstraints();
        $this->assertLandingColumnsExistWithDefaults();
        $this->assertCommerceSchemaExists();
        $this->assertAttributionSchemaExists();
        $this->assertHistoricalIdentitiesBackfilled();

        // down en orden inverso
        $this->runMigrationOnTempConnection($visitorIdentityMigration, 'down');
        $this->runMigrationOnTempConnection($attributionMigration, 'down');
        $this->assertAttributionSchemaMissing();
        $this->runMigrationOnTempConnection($commerceMigration, 'down');
        $this->assertCommerceSchemaMissing();
        $this->runMigrationOnTempConnection($landingMigration, 'down');
        $this->assertLandingColumnsMissing();
        $this->runMigrationOnTempConnection($baseMigration, 'down');
        $this->assertMarketingTablesMissing();

        // Segunda ejecución limpia
        $this->runMigrationOnTempConnection($baseMigration, 'up');
        $this->runMigrationOnTempConnection($landingMigration, 'up');
        $this->runMigrationOnTempConnection($commerceMigration, 'up');
        $this->runMigrationOnTempConnection($attributionMigration, 'up');
        $this->runMigrationOnTempConnection($visitorIdentityMigration, 'up');
        $this->assertMarketingTablesExist();
        $this->assertNamedConstraints();
        $this->assertLandingColumnsExistWithDefaults();
        $this->assertCommerceSchemaExists();
        $this->assertAttributionSchemaExists();

        $this->runMigrationOnTempConnection($visitorIdentityMigration, 'down');
        $this->runMigrationOnTempConnection($attributionMigration, 'down');
        $this->assertAttributionSchemaMissing();
        $this->runMigrationOnTempConnection($commerceMigration, 'down');
        $this->assertCommerceSchemaMissing();
        $this->runMigrationOnTempConnection($landingMigration, 'down');
        $this->assertLandingColumnsMissing();
        $this->runMigrationOnTempConnection($baseMigration, 'down');
        $this->assertMarketingTablesMissing();
    }

    private function configureTemporaryMysqlConnection(): bool
    {
        $host = env('MARKETING_CAMPAIGN_MYSQL_HOST', env('DB_HOST', 'mysql'));
        $port = (int) env('MARKETING_CAMPAIGN_MYSQL_PORT', env('DB_PORT', 3306));
        $username = env('MARKETING_CAMPAIGN_MYSQL_USERNAME', env('DB_USERNAME', 'famedic'));
        $password = env('MARKETING_CAMPAIGN_MYSQL_PASSWORD', env('DB_PASSWORD', 'famedic'));
        $rootPassword = env('MARKETING_CAMPAIGN_MYSQL_ROOT_PASSWORD', env('MYSQL_ROOT_PASSWORD', 'root'));

        try {
            config([
                'database.connections.mysql_root_audit' => [
                    'driver' => 'mysql',
                    'host' => $host,
                    'port' => $port,
                    'database' => null,
                    'username' => 'root',
                    'password' => $rootPassword,
                    'charset' => 'utf8mb4',
                    'collation' => 'utf8mb4_unicode_ci',
                ],
            ]);

            DB::purge('mysql_root_audit');
            DB::connection('mysql_root_audit')->getPdo();

            $this->tempDatabase = 'famedic_mc_mig_'.substr(md5((string) microtime(true)), 0, 10);
            DB::connection('mysql_root_audit')->statement(
                "CREATE DATABASE `{$this->tempDatabase}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
            );

            // Concede uso al usuario de app si existe
            try {
                DB::connection('mysql_root_audit')->statement(
                    "GRANT ALL PRIVILEGES ON `{$this->tempDatabase}`.* TO '{$username}'@'%'"
                );
                DB::connection('mysql_root_audit')->statement('FLUSH PRIVILEGES');
            } catch (\Throwable) {
                // root-only environments still work below with root credentials
                $username = 'root';
                $password = $rootPassword;
            }

            config([
                "database.connections.{$this->connection}" => [
                    'driver' => 'mysql',
                    'host' => $host,
                    'port' => $port,
                    'database' => $this->tempDatabase,
                    'username' => $username,
                    'password' => $password,
                    'charset' => 'utf8mb4',
                    'collation' => 'utf8mb4_unicode_ci',
                    'prefix' => '',
                    'strict' => true,
                    'engine' => null,
                ],
            ]);

            DB::purge($this->connection);
            DB::connection($this->connection)->getPdo();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function createParentTables(): void
    {
        $schema = Schema::connection($this->connection);

        $schema->create('users', function ($table) {
            $table->id();
            $table->string('email')->nullable();
            $table->timestamps();
        });

        $schema->create('administrators', function ($table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        $schema->create('customers', function ($table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        $schema->create('laboratory_tests', function ($table) {
            $table->id();
            $table->string('brand', 80)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        $schema->create('laboratory_test_categories', function ($table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
    }

    private function runMigrationOnTempConnection(object $migration, string $direction): void
    {
        $default = DB::getDefaultConnection();
        DB::setDefaultConnection($this->connection);

        try {
            $migration->{$direction}();
        } finally {
            DB::setDefaultConnection($default);
        }
    }

    private function assertMarketingTablesExist(): void
    {
        $schema = Schema::connection($this->connection);

        foreach ([
            'marketing_campaigns',
            'marketing_campaign_links',
            'marketing_campaign_link_aliases',
            'marketing_campaign_collections',
            'marketing_campaign_collection_items',
            'marketing_campaign_visits',
            'marketing_campaign_attributions',
            'marketing_campaign_visitor_identities',
        ] as $table) {
            $this->assertTrue($schema->hasTable($table), "Falta tabla {$table}");
        }
    }

    private function assertMarketingTablesMissing(): void
    {
        $schema = Schema::connection($this->connection);

        foreach ([
            'marketing_campaigns',
            'marketing_campaign_links',
            'marketing_campaign_link_aliases',
            'marketing_campaign_collections',
            'marketing_campaign_collection_items',
            'marketing_campaign_visits',
            'marketing_campaign_attributions',
            'marketing_campaign_visitor_identities',
        ] as $table) {
            $this->assertFalse($schema->hasTable($table), "La tabla {$table} no debió existir tras down()");
        }
    }

    private function assertNamedConstraints(): void
    {
        $database = $this->tempDatabase;

        $foreignKeys = collect(DB::connection($this->connection)->select(
            'SELECT CONSTRAINT_NAME, TABLE_NAME
             FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = ? AND CONSTRAINT_TYPE = ?',
            [$database, 'FOREIGN KEY']
        ))->pluck('CONSTRAINT_NAME')->all();

        foreach ([
            'mc_link_aliases_link_fk',
            'mc_collection_items_collection_fk',
            'mc_collection_items_test_fk',
            'mc_links_campaign_fk',
            'mc_collections_campaign_fk',
            'mc_campaigns_created_by_fk',
            'mc_links_created_by_fk',
        ] as $name) {
            $this->assertContains($name, $foreignKeys, "FK ausente: {$name}");
            $this->assertLessThan(65, strlen($name), "Nombre FK demasiado largo: {$name}");
        }

        $uniqueIndexes = collect(DB::connection($this->connection)->select(
            'SELECT INDEX_NAME, TABLE_NAME
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = ? AND NON_UNIQUE = 0',
            [$database]
        ))->pluck('INDEX_NAME')->all();

        foreach (['mc_links_slug_unique', 'mc_link_aliases_slug_unique', 'mc_collection_items_unique_test'] as $name) {
            $this->assertContains($name, $uniqueIndexes, "Unique ausente: {$name}");
            $this->assertLessThan(65, strlen($name));
        }
    }

    private function assertLandingColumnsExistWithDefaults(): void
    {
        $schema = Schema::connection($this->connection);

        foreach ([
            'public_title',
            'public_subtitle',
            'public_description',
            'eyebrow',
            'hero_image_path',
            'primary_cta_label',
            'secondary_cta_label',
            'show_prices',
            'show_brand_logo',
            'show_campaign_dates',
            'landing_layout',
        ] as $column) {
            $this->assertTrue(
                $schema->hasColumn('marketing_campaign_links', $column),
                "Falta columna landing {$column}"
            );
        }

        $defaults = collect(DB::connection($this->connection)->select(
            'SELECT COLUMN_NAME, COLUMN_DEFAULT, DATA_TYPE, IS_NULLABLE
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$this->tempDatabase, 'marketing_campaign_links']
        ))->keyBy('COLUMN_NAME');

        $this->assertTrue(($defaults['public_title']->IS_NULLABLE ?? null) === 'YES');
        $this->assertSame('1', (string) ($defaults['show_prices']->COLUMN_DEFAULT ?? ''));
        $this->assertSame('1', (string) ($defaults['show_brand_logo']->COLUMN_DEFAULT ?? ''));
        $this->assertSame('0', (string) ($defaults['show_campaign_dates']->COLUMN_DEFAULT ?? ''));
        $this->assertSame('default', (string) ($defaults['landing_layout']->COLUMN_DEFAULT ?? ''));
    }

    private function assertLandingColumnsMissing(): void
    {
        $schema = Schema::connection($this->connection);

        foreach (['public_title', 'show_prices', 'landing_layout'] as $column) {
            $this->assertFalse(
                $schema->hasColumn('marketing_campaign_links', $column),
                "La columna {$column} no debió existir tras down de 230200"
            );
        }
    }

    private function assertCommerceSchemaExists(): void
    {
        $schema = Schema::connection($this->connection);

        foreach ([
            'marketing_campaign_link_products',
            'marketing_campaign_link_categories',
            'marketing_campaign_link_images',
        ] as $table) {
            $this->assertTrue($schema->hasTable($table), "Falta tabla {$table}");
        }

        foreach ([
            'hero_image_source',
            'hero_image_disk',
            'hero_image_url',
            'hero_image_alt',
        ] as $column) {
            $this->assertTrue(
                $schema->hasColumn('marketing_campaign_links', $column),
                "Falta columna commerce {$column}"
            );
        }

        $database = $this->tempDatabase;

        $foreignKeys = collect(DB::connection($this->connection)->select(
            'SELECT CONSTRAINT_NAME
             FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = ? AND CONSTRAINT_TYPE = ?',
            [$database, 'FOREIGN KEY']
        ))->pluck('CONSTRAINT_NAME')->all();

        foreach ([
            'mc_link_products_link_fk',
            'mc_link_products_test_fk',
            'mc_link_categories_link_fk',
            'mc_link_categories_cat_fk',
            'mc_link_images_link_fk',
        ] as $name) {
            $this->assertContains($name, $foreignKeys, "FK ausente: {$name}");
            $this->assertLessThan(65, strlen($name));
        }

        $uniqueIndexes = collect(DB::connection($this->connection)->select(
            'SELECT INDEX_NAME
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = ? AND NON_UNIQUE = 0',
            [$database]
        ))->pluck('INDEX_NAME')->all();

        foreach (['mc_link_products_unique', 'mc_link_categories_unique'] as $name) {
            $this->assertContains($name, $uniqueIndexes, "Unique ausente: {$name}");
            $this->assertLessThan(65, strlen($name));
        }
    }

    private function assertCommerceSchemaMissing(): void
    {
        $schema = Schema::connection($this->connection);

        foreach ([
            'marketing_campaign_link_products',
            'marketing_campaign_link_categories',
            'marketing_campaign_link_images',
        ] as $table) {
            $this->assertFalse($schema->hasTable($table), "La tabla {$table} no debió existir tras down de 230300");
        }

        foreach (['hero_image_source', 'hero_image_url'] as $column) {
            $this->assertFalse(
                $schema->hasColumn('marketing_campaign_links', $column),
                "La columna {$column} no debió existir tras down de 230300"
            );
        }
    }

    private function assertAttributionSchemaExists(): void
    {
        $schema = Schema::connection($this->connection);

        foreach ([
            'marketing_campaign_visits',
            'marketing_campaign_attributions',
            'marketing_campaign_visitor_identities',
        ] as $table) {
            $this->assertTrue($schema->hasTable($table), "Falta tabla {$table}");
        }

        $foreignKeys = collect(DB::connection($this->connection)->select(
            'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = ? AND CONSTRAINT_TYPE = ?',
            [$this->tempDatabase, 'FOREIGN KEY']
        ))->pluck('CONSTRAINT_NAME')->all();

        foreach ([
            'mc_visits_campaign_fk',
            'mc_visits_link_fk',
            'mc_visits_attribution_fk',
            'mc_attr_first_visit_fk',
            'mc_attr_last_visit_fk',
            'mc_attr_vid_fk',
            'mc_visits_vid_fk',
            'mc_attr_first_campaign_fk',
            'mc_attr_last_link_fk',
        ] as $name) {
            $this->assertContains($name, $foreignKeys, "FK ausente: {$name}");
            $this->assertLessThan(65, strlen($name));
        }

        $indexes = collect(DB::connection($this->connection)->select(
            'SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ?',
            [$this->tempDatabase]
        ))->pluck('INDEX_NAME')->all();

        foreach ([
            'mc_attr_token_hash_idx',
            'mc_vid_token_hash_unique',
            'mc_attr_vid_idx',
            'mc_visits_vid_idx',
            'mc_visits_campaign_visited_idx',
            'mc_visits_link_visited_idx',
        ] as $name) {
            $this->assertContains($name, $indexes, "Índice ausente: {$name}");
        }
    }

    private function seedHistoricalAttributionFixtures(): void
    {
        $db = DB::connection($this->connection);
        $now = now()->format('Y-m-d H:i:s');
        $old = now()->subDays(40)->format('Y-m-d H:i:s');
        $expires = now()->addDays(30)->format('Y-m-d H:i:s');
        $expired = now()->subDay()->format('Y-m-d H:i:s');
        $sharedHash = str_repeat('a', 64);
        $visitOnlyHash = str_repeat('b', 64);
        $legacyInvalidHash = 'legacy-invalid-hash';

        $db->table('marketing_campaigns')->insert([
            'id' => 1001,
            'name' => 'Migracion historica',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $db->table('marketing_campaign_links')->insert([
            [
                'id' => 2001,
                'marketing_campaign_id' => 1001,
                'name' => 'Historico A',
                'slug' => 'historico-a',
                'status' => 'active',
                'target_type' => 'brand',
                'target_payload' => json_encode(['brand' => 'olab']),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 2002,
                'marketing_campaign_id' => 1001,
                'name' => 'Historico B',
                'slug' => 'historico-b',
                'status' => 'active',
                'target_type' => 'brand',
                'target_payload' => json_encode(['brand' => 'olab']),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $db->table('marketing_campaign_attributions')->insert([
            [
                'id' => 3001,
                'visitor_token_hash' => $sharedHash,
                'first_campaign_id' => 1001,
                'first_link_id' => 2001,
                'last_campaign_id' => 1001,
                'last_link_id' => 2001,
                'first_touched_at' => $old,
                'last_touched_at' => $old,
                'expires_at' => $expired,
                'created_at' => $old,
                'updated_at' => $old,
            ],
            [
                'id' => 3002,
                'visitor_token_hash' => $sharedHash,
                'first_campaign_id' => 1001,
                'first_link_id' => 2001,
                'last_campaign_id' => 1001,
                'last_link_id' => 2002,
                'first_touched_at' => $now,
                'last_touched_at' => $now,
                'expires_at' => $expires,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 3003,
                'visitor_token_hash' => $legacyInvalidHash,
                'first_campaign_id' => 1001,
                'first_link_id' => 2002,
                'last_campaign_id' => 1001,
                'last_link_id' => 2002,
                'first_touched_at' => $old,
                'last_touched_at' => $old,
                'expires_at' => $expired,
                'created_at' => $old,
                'updated_at' => $old,
            ],
        ]);

        $db->table('marketing_campaign_visits')->insert([
            [
                'id' => 4001,
                'marketing_campaign_id' => 1001,
                'marketing_campaign_link_id' => 2001,
                'marketing_campaign_attribution_id' => 3001,
                'visitor_token_hash' => $sharedHash,
                'landing_path' => '/c/historico-a',
                'visited_at' => $old,
                'created_at' => $old,
            ],
            [
                'id' => 4002,
                'marketing_campaign_id' => 1001,
                'marketing_campaign_link_id' => 2002,
                'marketing_campaign_attribution_id' => null,
                'visitor_token_hash' => $visitOnlyHash,
                'landing_path' => '/c/historico-b',
                'visited_at' => $now,
                'created_at' => $now,
            ],
        ]);
    }

    private function assertHistoricalIdentitiesBackfilled(): void
    {
        $db = DB::connection($this->connection);
        $sharedHash = str_repeat('a', 64);
        $visitOnlyHash = str_repeat('b', 64);
        $legacyInvalidHash = 'legacy-invalid-hash';

        $this->assertSame(3, $db->table('marketing_campaign_visitor_identities')->count());
        $this->assertSame(1, $db->table('marketing_campaign_visitor_identities')->where('visitor_token_hash', $sharedHash)->count());
        $this->assertSame(1, $db->table('marketing_campaign_visitor_identities')->where('visitor_token_hash', $visitOnlyHash)->count());
        $this->assertSame(1, $db->table('marketing_campaign_visitor_identities')->where('visitor_token_hash', $legacyInvalidHash)->count());

        $this->assertSame(0, $db->table('marketing_campaign_attributions')->whereNull('marketing_campaign_visitor_identity_id')->count());
        $this->assertSame(0, $db->table('marketing_campaign_visits')->whereNull('marketing_campaign_visitor_identity_id')->count());
        $this->assertSame(
            1,
            $db->table('marketing_campaign_attributions')
                ->where('visitor_token_hash', $sharedHash)
                ->distinct()
                ->count('marketing_campaign_visitor_identity_id'),
        );
        $this->assertSame(
            '2026',
            substr((string) $db->table('marketing_campaign_attributions')->where('id', 3002)->value('first_touched_at'), 0, 4),
        );
    }

    private function assertAttributionSchemaMissing(): void
    {
        $schema = Schema::connection($this->connection);

        foreach ([
            'marketing_campaign_visits',
            'marketing_campaign_attributions',
            'marketing_campaign_visitor_identities',
        ] as $table) {
            $this->assertFalse($schema->hasTable($table), "La tabla {$table} no debió existir tras down de 230400");
        }
    }
}
