<?php

namespace Tests\Feature;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Monolog\Handler\NullHandler;
use RuntimeException;
use Tests\TestCase;

class PermissionSchemaTest extends TestCase
{
    public function test_test_log_channel_discards_reported_exceptions_without_storage_file(): void
    {
        $logPath = storage_path('logs/laravel.log');
        $this->assertFalse(file_exists($logPath), 'Test must start without a storage log.');

        app(ExceptionHandler::class)->report(
            new RuntimeException('permission test logger regression')
        );

        $this->assertSame('test_null', env('LOG_CHANNEL'));
        $this->assertSame('test_null', config('logging.default'));
        $this->assertSame(
            NullHandler::class,
            config('logging.channels.test_null.handler')
        );
        $this->assertFalse(
            file_exists($logPath),
            'The test logger must not create '.$logPath
        );
    }

    public function test_phpunit_isolation_entries_are_forced_against_external_environment(): void
    {
        $phpunitXml = file_get_contents(base_path('phpunit.xml'));

        $this->assertSame(
            1,
            preg_match('/<php>(.*?)<\/php>/s', $phpunitXml, $phpSection)
        );
        preg_match_all('/<(?:env|server)\b[^>]*\/>/', $phpSection[1], $entryTags);

        $entries = [];
        foreach ($entryTags[0] as $entryTag) {
            $this->assertSame(1, preg_match('/\bname="([^"]+)"/', $entryTag, $name));
            preg_match('/\bforce="([^"]+)"/', $entryTag, $force);
            $entries[$name[1]] = $force[1] ?? null;
        }

        $expectedNames = [
            'APP_ENV',
            'APP_KEY',
            'LOG_CHANNEL',
            'DATABASE_URL',
            'DB_CONNECTION',
            'DB_HOST',
            'DB_PORT',
            'DB_DATABASE',
            'DB_USERNAME',
            'DB_PASSWORD',
            'DB_SOCKET',
            'DEVICE_COOKIE_LIFETIME_MINUTES',
            'DEVICE_COOKIE_NAME',
            'DEVICE_COOKIE_SECURE',
            'REDIS_URL',
            'REDIS_HOST',
            'REDIS_PORT',
            'REDIS_PASSWORD',
            'MARKET_CHANGE_SOURCE',
            'MARKET_CHANGE_REDIS_DB',
            'MARKET_CHANGE_REDIS_PREFIX',
            'MARKET_CHANGE_REDIS_MAX_AGE_SECONDS',
            'MARKET_CHANGE_SHADOW_SAMPLE_PERCENT',
            'MARKET_CHANGE_ERROR_LOG_INTERVAL_SECONDS',
            'PERMISSION_ROOT_USER_ID',
            'VIEW_COMPILED_PATH',
            'BCRYPT_ROUNDS',
            'CACHE_DRIVER',
            'MAIL_DRIVER',
            'QUEUE_CONNECTION',
            'SESSION_DRIVER',
        ];

        $actualNames = array_keys($entries);
        sort($expectedNames);
        sort($actualNames);

        $this->assertSame($expectedNames, $actualNames);
        $this->assertCount(count($entryTags[0]), $entries);

        foreach ($entries as $name => $force) {
            $this->assertSame('true', $force, $name.' must force the test value');
        }
    }

    public function test_phpunit_resolves_only_the_isolated_service_targets(): void
    {
        $mysql = config('database.connections.mysql');

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', config('database.default'));
        $this->assertSame('', (string) $mysql['url']);
        $this->assertSame('mysql', $mysql['host']);
        $this->assertSame('3306', (string) $mysql['port']);
        $this->assertSame('tool_permissions_test', $mysql['database']);
        $this->assertSame('test_runner', $mysql['username']);
        $this->assertSame('', (string) $mysql['unix_socket']);
        $this->assertSame('', (string) config('database.redis.default.url'));
        $this->assertSame('redis', config('database.redis.default.host'));
        $this->assertSame('6379', (string) config('database.redis.default.port'));
        $this->assertSame('', (string) config('database.redis.default.password'));
    }

    public function test_permission_tables_have_required_constraints(): void
    {
        $this->assertTrue(Schema::hasTable('user_permissions'));
        $this->assertTrue(Schema::hasTable('permission_change_logs'));

        $indexes = DB::select('SHOW INDEX FROM user_permissions');
        $hasRequiredUniqueIndex = collect($indexes)
            ->where('Non_unique', 0)
            ->groupBy('Key_name')
            ->map(function ($rows) {
                return $rows->sortBy('Seq_in_index')->pluck('Column_name')->all();
            })
            ->contains(['user_id', 'permission_code']);

        $this->assertTrue($hasRequiredUniqueIndex);

        $foreignKeys = collect(DB::select(
            "SELECT kcu.COLUMN_NAME, rc.DELETE_RULE
             FROM information_schema.KEY_COLUMN_USAGE AS kcu
             JOIN information_schema.REFERENTIAL_CONSTRAINTS AS rc
               ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
              AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
             WHERE kcu.CONSTRAINT_SCHEMA = DATABASE()
               AND kcu.TABLE_NAME = 'user_permissions'
               AND kcu.REFERENCED_TABLE_NAME = 'users'"
        ))->keyBy('COLUMN_NAME');

        $this->assertSame('CASCADE', $foreignKeys->get('user_id')->DELETE_RULE);
        $this->assertSame('SET NULL', $foreignKeys->get('granted_by')->DELETE_RULE);

        $auditUserForeignKeyCount = DB::selectOne(
            "SELECT COUNT(*) AS aggregate
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND TABLE_NAME = 'permission_change_logs'
               AND REFERENCED_TABLE_NAME = 'users'"
        );

        $this->assertSame(0, (int) $auditUserForeignKeyCount->aggregate);

        $actionChecks = DB::select(
            "SELECT cc.CHECK_CLAUSE
             FROM information_schema.TABLE_CONSTRAINTS AS tc
             JOIN information_schema.CHECK_CONSTRAINTS AS cc
               ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA
              AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
             WHERE tc.CONSTRAINT_SCHEMA = DATABASE()
               AND tc.TABLE_NAME = 'permission_change_logs'
               AND tc.CONSTRAINT_TYPE = 'CHECK'"
        );

        $this->assertCount(1, $actionChecks);
        $this->assertStringContainsString('action', strtolower($actionChecks[0]->CHECK_CLAUSE));

        $normalizedCheckClause = str_replace('\\', '', $actionChecks[0]->CHECK_CLAUSE);
        preg_match_all("/'([^']+)'/", $normalizedCheckClause, $matches);
        $allowedActions = array_values(array_unique($matches[1]));
        sort($allowedActions);

        $this->assertSame(['grant', 'revoke'], $allowedActions);
    }
}
