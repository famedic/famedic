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
                    'marketing_campaign_collection_items',
                    'marketing_campaign_collections',
                    'marketing_campaign_link_aliases',
                    'marketing_campaign_links',
                    'marketing_campaigns',
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

        $migration = require database_path('migrations/2026_08_06_230000_create_marketing_campaign_tables.php');

        // Primera ejecución up
        $this->runMigrationOnTempConnection($migration, 'up');
        $this->assertMarketingTablesExist();
        $this->assertNamedConstraints();

        // down completo
        $this->runMigrationOnTempConnection($migration, 'down');
        $this->assertMarketingTablesMissing();

        // Segunda ejecución limpia
        $this->runMigrationOnTempConnection($migration, 'up');
        $this->assertMarketingTablesExist();
        $this->assertNamedConstraints();

        $this->runMigrationOnTempConnection($migration, 'down');
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

        $schema->create('laboratory_tests', function ($table) {
            $table->id();
            $table->string('brand', 80)->nullable();
            $table->timestamps();
            $table->softDeletes();
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
}
