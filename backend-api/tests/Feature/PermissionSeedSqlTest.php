<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PermissionSeedSqlTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->assertIsolatedDatabaseTarget();

        DB::table('permission_change_logs')->delete();
        DB::table('user_permissions')->delete();
        DB::table('users')->delete();

        DB::table('users')->insert([
            ['id' => 31, 'account' => 'root-admin', 'pwd' => 'test-password', 'is_admin' => 1],
            ['id' => 32, 'account' => 'admin-two', 'pwd' => 'test-password', 'is_admin' => 1],
            ['id' => 33, 'account' => 'admin-three', 'pwd' => 'test-password', 'is_admin' => 1],
            ['id' => 34, 'account' => 'regular-one', 'pwd' => 'test-password', 'is_admin' => 0],
            ['id' => 35, 'account' => 'regular-two', 'pwd' => 'test-password', 'is_admin' => 0],
        ]);
    }

    private function assertIsolatedDatabaseTarget(): void
    {
        $mysql = config('database.connections.mysql');
        $isIsolated = app()->environment('testing')
            && config('database.default') === 'mysql'
            && (string) $mysql['url'] === ''
            && $mysql['host'] === 'mysql'
            && (string) $mysql['port'] === '3306'
            && $mysql['database'] === 'tool_permissions_test'
            && $mysql['username'] === 'test_runner'
            && (string) $mysql['unix_socket'] === '';

        if (!$isIsolated) {
            throw new \RuntimeException(
                'Refusing destructive permission seed test outside the isolated database.'
            );
        }
    }

    public function test_seed_grants_the_legacy_admin_contract_once(): void
    {
        $seedSqlPath = database_path('sql/2026-07-20-02-seed-user-permissions.sql');

        $this->assertFileExists($seedSqlPath);
        $this->runSqlFile($seedSqlPath);

        $this->assertSame(37, DB::table('user_permissions')->count());
        $this->assertSame(37, DB::table('permission_change_logs')->count());
        $this->assertSame(13, DB::table('user_permissions')->where('user_id', 31)->count());
        $this->assertSame(12, DB::table('user_permissions')->where('user_id', 32)->count());
        $this->assertSame(12, DB::table('user_permissions')->where('user_id', 33)->count());
        $this->assertSame(0, DB::table('user_permissions')->whereIn('user_id', [34, 35])->count());
        $this->assertSame(
            1,
            DB::table('user_permissions')
                ->where('user_id', 31)
                ->where('permission_code', 'permissions.manage')
                ->count()
        );
        $this->assertSame(
            0,
            DB::table('user_permissions')
                ->whereIn('user_id', [32, 33])
                ->where('permission_code', 'permissions.manage')
                ->count()
        );
        $this->assertSame(
            0,
            DB::table('user_permissions')
                ->where('permission_code', 'quotation.profit.view')
                ->count()
        );
        $this->assertSame(
            0,
            DB::table('permission_change_logs')
                ->where('permission_code', 'quotation.profit.view')
                ->count()
        );
        $this->assertSame(
            0,
            DB::table('user_permissions')
                ->whereIn('permission_code', [
                    'quotation.extreme.view',
                    'quotation.extreme.config',
                ])
                ->count()
        );
        $this->assertSame(
            0,
            DB::table('permission_change_logs')
                ->whereIn('permission_code', [
                    'quotation.extreme.view',
                    'quotation.extreme.config',
                ])
                ->count()
        );
        $this->assertSame(
            37,
            DB::table('permission_change_logs')
                ->where('action', 'grant')
                ->where('operator_user_id', 31)
                ->where('operator_account', 'root-admin')
                ->count()
        );

        $this->runSqlFile($seedSqlPath);

        $this->assertSame(37, DB::table('user_permissions')->count());
        $this->assertSame(37, DB::table('permission_change_logs')->count());
    }

    public function test_seed_aborts_before_granting_when_root_user_is_missing(): void
    {
        DB::table('users')->where('id', 31)->delete();
        $exception = null;

        try {
            $this->runSqlFile(database_path('sql/2026-07-20-02-seed-user-permissions.sql'));
        } catch (QueryException $caught) {
            $exception = $caught;
        } finally {
            DB::unprepared('ROLLBACK');
            DB::unprepared('DROP TEMPORARY TABLE IF EXISTS `seed_permission_root_guard`');
        }

        $this->assertInstanceOf(QueryException::class, $exception);
        $this->assertStringContainsString('seed_permission_root_guard', $exception->getMessage());
        $this->assertSame(0, DB::table('user_permissions')->count());
        $this->assertSame(0, DB::table('permission_change_logs')->count());
    }

    private function runSqlFile(string $path): void
    {
        $statements = preg_split('/;\s*(?:\r?\n|$)/', trim(file_get_contents($path)));

        foreach ($statements as $statement) {
            if (trim($statement) !== '') {
                DB::unprepared($statement);
            }
        }
    }
}
