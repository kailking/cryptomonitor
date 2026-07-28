<?php

namespace Tests\Feature;

use App\Model\Users;
use App\Service\RedisService;
use App\Services\PermissionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

const PERMISSION_WORKER_TIMEOUT_SECONDS = 15;

if (PHP_SAPI === 'cli' && isset($argv[1]) && $argv[1] === '--permission-worker') {
    runPermissionTransactionWorker(array_slice($argv, 2));
}

class PermissionTransactionTest extends TestCase
{
    private const WORKER_TIMEOUT_MILLISECONDS =
        PERMISSION_WORKER_TIMEOUT_SECONDS * 1000;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertIsolatedDatabaseTarget();
        $this->assertNoPermissionWorkerResidue();
        $this->dropForcedAuditFailureConstraint();
        DB::table('permission_change_logs')->delete();
        DB::table('user_permissions')->delete();
        DB::table('users')->delete();

        DB::table('users')->insert([
            ['id' => 31, 'account' => 'root-admin', 'pwd' => 'test-password', 'is_admin' => 1],
            ['id' => 35, 'account' => 'permission-manager', 'pwd' => 'test-password', 'is_admin' => 0],
            ['id' => 40, 'account' => 'target-user', 'pwd' => 'test-password', 'is_admin' => 0],
        ]);
        DB::table('user_permissions')->insert([
            [
                'user_id' => 31,
                'permission_code' => 'permissions.manage',
                'granted_by' => 31,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'user_id' => 35,
                'permission_code' => 'permissions.manage',
                'granted_by' => 31,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->redis()->del('user_token_40');
    }

    protected function tearDown(): void
    {
        try {
            $this->dropForcedAuditFailureConstraint();
            $this->redis()->del('user_token_40');
            $this->assertNoPermissionWorkerResidue();
        } finally {
            parent::tearDown();
        }
    }

    public function test_grants_and_one_audit_row_per_grant_commit_together(): void
    {
        $result = $this->service()->savePermissions(
            Users::findOrFail(31),
            Users::findOrFail(40),
            ['users.edit']
        );

        $this->assertSame(['users.edit', 'users.view'], $result['normalized']);
        $this->assertSame(['users.edit', 'users.view'], $this->permissionCodes());
        $this->assertSame(['users.edit', 'users.view'], $this->auditCodes('grant'));
        $this->assertSame(
            2,
            DB::table('permission_change_logs')
                ->where('target_user_id', 40)
                ->where('target_account', 'target-user')
                ->where('operator_user_id', 31)
                ->where('operator_account', 'root-admin')
                ->count()
        );
    }

    public function test_revokes_and_one_audit_row_per_revoke_commit_together(): void
    {
        $this->service()->savePermissions(
            Users::findOrFail(31),
            Users::findOrFail(40),
            ['users.edit']
        );

        $result = $this->service()->savePermissions(
            Users::findOrFail(31),
            Users::findOrFail(40),
            []
        );

        $this->assertSame([], $result['normalized']);
        $this->assertSame([], $this->permissionCodes());
        $this->assertSame(['users.edit', 'users.view'], $this->auditCodes('revoke'));
    }

    public function test_audit_failure_rolls_back_grants_and_does_not_clear_the_token(): void
    {
        $this->redis()->set('user_token_40', 'token-must-survive');
        $this->addForcedAuditFailureConstraint();

        try {
            $this->service()->savePermissions(
                Users::findOrFail(31),
                Users::findOrFail(40),
                ['users.view']
            );
            $this->fail('Expected forced audit failure.');
        } catch (\Throwable $exception) {
            $this->assertStringContainsString(
                'permission_logs_forced_failure_check',
                $exception->getMessage()
            );
        }

        $this->assertSame([], $this->permissionCodes());
        $this->assertSame(0, DB::table('permission_change_logs')->count());
        $this->assertSame('token-must-survive', $this->redis()->get('user_token_40'));
    }

    public function test_audit_failure_rolls_back_revokes(): void
    {
        $this->insertGrant('users.view');
        $this->addForcedAuditFailureConstraint();

        try {
            $this->service()->savePermissions(
                Users::findOrFail(31),
                Users::findOrFail(40),
                []
            );
            $this->fail('Expected forced audit failure.');
        } catch (\Throwable $exception) {
            $this->assertStringContainsString(
                'permission_logs_forced_failure_check',
                $exception->getMessage()
            );
        }

        $this->assertSame(['users.view'], $this->permissionCodes());
        $this->assertSame(0, DB::table('permission_change_logs')->count());
    }

    public function test_last_full_set_save_wins_and_preserves_both_rounds_of_audit(): void
    {
        $this->service()->savePermissions(
            Users::findOrFail(31),
            Users::findOrFail(40),
            ['users.edit']
        );

        $this->service()->savePermissions(
            Users::findOrFail(31),
            Users::findOrFail(40),
            ['settings.market.update']
        );

        $this->assertSame(
            ['settings.market.update', 'settings.market.view'],
            $this->permissionCodes()
        );
        $this->assertSame(6, DB::table('permission_change_logs')->count());
        $this->assertSame(4, DB::table('permission_change_logs')->where('action', 'grant')->count());
        $this->assertSame(2, DB::table('permission_change_logs')->where('action', 'revoke')->count());
    }

    public function test_profit_only_change_does_not_clear_the_token(): void
    {
        $this->redis()->set('user_token_40', 'profit-token');

        $this->service()->savePermissions(
            Users::findOrFail(31),
            Users::findOrFail(40),
            ['quotation.profit.view']
        );

        $this->assertSame('profit-token', $this->redis()->get('user_token_40'));

        $this->redis()->set('user_token_40', 'profit-token-after-grant');

        $this->service()->savePermissions(
            Users::findOrFail(31),
            Users::findOrFail(40),
            []
        );

        $this->assertSame(
            'profit-token-after-grant',
            $this->redis()->get('user_token_40')
        );
    }

    public function test_any_sensitive_difference_clears_the_token_after_success(): void
    {
        $this->redis()->set('user_token_40', 'sensitive-token');

        $this->service()->savePermissions(
            Users::findOrFail(31),
            Users::findOrFail(40),
            ['quotation.profit.view', 'users.view']
        );

        $this->assertSame(0, $this->redis()->exists('user_token_40'));
        $this->assertSame(
            ['quotation.profit.view', 'users.view'],
            $this->permissionCodes()
        );
    }

    public function test_save_locks_the_current_complete_set_for_update(): void
    {
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $this->service()->savePermissions(
            Users::findOrFail(31),
            Users::findOrFail(40),
            ['quotation.profit.view']
        );

        $lockingQueries = array_filter($queries, function (string $query): bool {
            return strpos($query, 'user_permissions') !== false
                && strpos($query, 'for update') !== false;
        });

        $this->assertNotEmpty($lockingQueries);
    }

    public function test_save_locks_actor_and_target_users_in_ascending_order_before_grants(): void
    {
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = [
                'sql' => strtolower($query->sql),
                'bindings' => $query->bindings,
            ];
        });

        $this->service()->savePermissions(
            Users::findOrFail(35),
            Users::findOrFail(40),
            ['quotation.profit.view']
        );

        $userLockIndex = null;
        $grantLockIndex = null;
        foreach ($queries as $index => $query) {
            if (
                strpos($query['sql'], 'from `users`') !== false
                && strpos($query['sql'], 'for update') !== false
                && count($query['bindings']) === 2
            ) {
                $userLockIndex = $index;
                $this->assertStringContainsString(
                    'order by `id` asc',
                    $query['sql']
                );
            }
            if (
                strpos($query['sql'], 'from `user_permissions`') !== false
                && strpos($query['sql'], 'for update') !== false
            ) {
                $grantLockIndex = $index;
            }
        }

        $this->assertNotNull($userLockIndex);
        $this->assertNotNull($grantLockIndex);
        $this->assertLessThan($grantLockIndex, $userLockIndex);
    }

    public function test_same_actor_and_target_user_row_is_locked_only_once(): void
    {
        $userLockBindings = [];
        DB::listen(function (QueryExecuted $query) use (&$userLockBindings): void {
            $sql = strtolower($query->sql);
            if (
                strpos($sql, 'from `users`') !== false
                && strpos($sql, 'for update') !== false
            ) {
                $userLockBindings[] = $query->bindings;
            }
        });

        $this->service()->savePermissions(
            Users::findOrFail(31),
            Users::findOrFail(31),
            ['permissions.manage']
        );

        $this->assertCount(1, $userLockBindings);
        $this->assertSame([31], array_map('intval', $userLockBindings[0]));
    }

    public function test_two_first_writers_are_serialized_and_second_full_set_wins(): void
    {
        $directory = $this->makeWorkerDirectory();
        $first = null;
        $second = null;

        try {
            $first = $this->startWorker(
                $directory,
                'first',
                'pause-after-grant-lock',
                ['quotation.profit.view']
            );
            $this->assertTrue(
                $this->waitForMarker(
                    $directory.'/first.ready',
                    self::WORKER_TIMEOUT_MILLISECONDS,
                    $first
                ),
                'First worker did not finish isolated application bootstrap.'
            );
            $this->writeWorkerMarker($directory.'/first.go');
            $this->assertTrue(
                $this->waitForMarker(
                    $directory.'/first.locked',
                    self::WORKER_TIMEOUT_MILLISECONDS,
                    $first
                ),
                'First worker did not reach the empty grant-set lock.'
            );

            $second = $this->startWorker(
                $directory,
                'second',
                'pause-after-grant-lock',
                ['system.logs.view']
            );
            $this->assertTrue(
                $this->waitForMarker(
                    $directory.'/second.ready',
                    self::WORKER_TIMEOUT_MILLISECONDS,
                    $second
                ),
                'Second worker did not finish isolated application bootstrap.'
            );
            $this->writeWorkerMarker($directory.'/second.go');
            $secondReachedGrantLockEarly = $this->waitForMarker(
                $directory.'/second.locked',
                500,
                $second
            );

            $this->writeWorkerMarker($directory.'/first.release');
            $this->assertTrue(
                $this->waitForMarker(
                    $directory.'/second.locked',
                    self::WORKER_TIMEOUT_MILLISECONDS,
                    $second
                ),
                'Second worker never reached the grant lock after first release.'
            );
            $this->writeWorkerMarker($directory.'/second.release');
            $firstCompleted = $this->waitForMarker(
                $directory.'/first.done',
                self::WORKER_TIMEOUT_MILLISECONDS,
                $first
            );
            $secondCompleted = $this->waitForMarker(
                $directory.'/second.done',
                self::WORKER_TIMEOUT_MILLISECONDS,
                $second
            );

            $this->assertFalse(
                $secondReachedGrantLockEarly,
                'Second transaction was not serialized on the target user row.'
            );
            $this->assertTrue($firstCompleted);
            $this->assertTrue($secondCompleted);
            $this->assertWorkerSucceeded($directory, 'first');
            $this->assertWorkerSucceeded($directory, 'second');

            DB::purge('mysql');
            DB::reconnect('mysql');
            $this->assertSame(['system.logs.view'], $this->permissionCodes());
        } finally {
            @touch($directory.'/first.release');
            @touch($directory.'/second.release');
            @touch($directory.'/first.go');
            @touch($directory.'/second.go');
            $this->stopWorkers([$first, $second]);
            $this->removeWorkerDirectory($directory);
        }
    }

    public function test_later_empty_save_cannot_finish_before_an_earlier_first_writer(): void
    {
        $directory = $this->makeWorkerDirectory();
        $first = null;
        $second = null;

        try {
            $first = $this->startWorker(
                $directory,
                'first',
                'pause-after-grant-lock',
                ['quotation.profit.view']
            );
            $this->assertTrue(
                $this->waitForMarker(
                    $directory.'/first.ready',
                    self::WORKER_TIMEOUT_MILLISECONDS,
                    $first
                ),
                'First worker did not finish isolated application bootstrap.'
            );
            $this->writeWorkerMarker($directory.'/first.go');
            $this->assertTrue(
                $this->waitForMarker(
                    $directory.'/first.locked',
                    self::WORKER_TIMEOUT_MILLISECONDS,
                    $first
                ),
                'Earlier writer did not reach the empty grant-set lock.'
            );

            $second = $this->startWorker($directory, 'second', 'save', []);
            $this->assertTrue(
                $this->waitForMarker(
                    $directory.'/second.ready',
                    self::WORKER_TIMEOUT_MILLISECONDS,
                    $second
                ),
                'Second worker did not finish isolated application bootstrap.'
            );
            $this->writeWorkerMarker($directory.'/second.go');
            $secondCompletedEarly = $this->waitForMarker(
                $directory.'/second.done',
                500,
                $second
            );

            $this->writeWorkerMarker($directory.'/first.release');
            $this->assertTrue($this->waitForMarker(
                $directory.'/first.done',
                self::WORKER_TIMEOUT_MILLISECONDS,
                $first
            ));
            $this->assertTrue($this->waitForMarker(
                $directory.'/second.done',
                self::WORKER_TIMEOUT_MILLISECONDS,
                $second
            ));

            $this->assertFalse(
                $secondCompletedEarly,
                'A later empty save bypassed the earlier in-flight full-set save.'
            );
            $this->assertWorkerSucceeded($directory, 'first');
            $this->assertWorkerSucceeded($directory, 'second');

            DB::purge('mysql');
            DB::reconnect('mysql');
            $this->assertSame([], $this->permissionCodes());
        } finally {
            @touch($directory.'/first.release');
            @touch($directory.'/first.go');
            @touch($directory.'/second.go');
            $this->stopWorkers([$first, $second]);
            $this->removeWorkerDirectory($directory);
        }
    }

    public function test_save_fails_closed_when_the_target_user_no_longer_exists(): void
    {
        $missingTarget = new Users();
        $missingTarget->id = 999;
        $missingTarget->account = 'missing-target';

        try {
            $this->service()->savePermissions(
                Users::findOrFail(31),
                $missingTarget,
                ['quotation.profit.view']
            );
            $this->fail('Expected missing target rejection.');
        } catch (ModelNotFoundException $exception) {
            $this->assertSame(Users::class, $exception->getModel());
        }

        $this->assertSame(
            0,
            DB::table('user_permissions')->where('user_id', 999)->count()
        );
        $this->assertSame(0, DB::table('permission_change_logs')->count());
    }

    public function test_actor_grant_revoked_after_request_authorization_fails_closed(): void
    {
        $this->insertGrant('users.view');
        $directory = $this->makeWorkerDirectory();
        $worker = null;

        try {
            $worker = $this->startWorker(
                $directory,
                'revoked-actor',
                'pause-after-authorization',
                [],
                35
            );
            $this->assertTrue(
                $this->waitForMarker(
                    $directory.'/revoked-actor.ready',
                    self::WORKER_TIMEOUT_MILLISECONDS,
                    $worker
                )
            );
            $this->writeWorkerMarker($directory.'/revoked-actor.go');
            $this->assertTrue(
                $this->waitForMarker(
                    $directory.'/revoked-actor.authorized',
                    self::WORKER_TIMEOUT_MILLISECONDS,
                    $worker
                ),
                'Worker did not observe the pre-transaction actor grant.'
            );

            DB::table('user_permissions')
                ->where('user_id', 35)
                ->where('permission_code', 'permissions.manage')
                ->delete();
            $this->writeWorkerMarker($directory.'/revoked-actor.release');

            $this->assertTrue(
                $this->waitForMarker(
                    $directory.'/revoked-actor.error',
                    self::WORKER_TIMEOUT_MILLISECONDS,
                    $worker
                ),
                'Save did not reject the actor whose grant was revoked.'
            );
            $this->assertWorkerAuthorizationDenied(
                $directory,
                'revoked-actor'
            );

            DB::purge('mysql');
            DB::reconnect('mysql');
            $this->assertSame(['users.view'], $this->permissionCodes());
            $this->assertSame(0, DB::table('permission_change_logs')->count());
        } finally {
            @touch($directory.'/revoked-actor.release');
            @touch($directory.'/revoked-actor.go');
            $this->stopWorkers([$worker]);
            $this->removeWorkerDirectory($directory);
        }
    }

    public function test_actor_deleted_after_request_authorization_fails_closed_without_500(): void
    {
        $directory = $this->makeWorkerDirectory();
        $worker = null;

        try {
            $worker = $this->startWorker(
                $directory,
                'deleted-actor',
                'pause-after-authorization',
                ['users.view'],
                35
            );
            $this->assertTrue(
                $this->waitForMarker(
                    $directory.'/deleted-actor.ready',
                    self::WORKER_TIMEOUT_MILLISECONDS,
                    $worker
                )
            );
            $this->writeWorkerMarker($directory.'/deleted-actor.go');
            $this->assertTrue(
                $this->waitForMarker(
                    $directory.'/deleted-actor.authorized',
                    self::WORKER_TIMEOUT_MILLISECONDS,
                    $worker
                ),
                'Worker did not observe the actor before deletion.'
            );

            DB::table('users')->where('id', 35)->delete();
            $this->writeWorkerMarker($directory.'/deleted-actor.release');

            $this->assertTrue(
                $this->waitForMarker(
                    $directory.'/deleted-actor.error',
                    self::WORKER_TIMEOUT_MILLISECONDS,
                    $worker
                ),
                'Save did not reject the deleted actor.'
            );
            $this->assertWorkerAuthorizationDenied(
                $directory,
                'deleted-actor'
            );

            DB::purge('mysql');
            DB::reconnect('mysql');
            $this->assertSame([], $this->permissionCodes());
            $this->assertSame(0, DB::table('permission_change_logs')->count());
        } finally {
            @touch($directory.'/deleted-actor.release');
            @touch($directory.'/deleted-actor.go');
            $this->stopWorkers([$worker]);
            $this->removeWorkerDirectory($directory);
        }
    }

    public function test_save_then_revoke_linearizes_on_actor_user_lock_without_deadlock(): void
    {
        $directory = $this->makeWorkerDirectory();
        $save = null;
        $revoke = null;

        try {
            $save = $this->startWorker(
                $directory,
                'save',
                'pause-after-user-lock',
                ['quotation.profit.view'],
                35,
                40
            );
            $this->assertTrue($this->waitForMarker(
                $directory.'/save.ready',
                self::WORKER_TIMEOUT_MILLISECONDS,
                $save
            ));
            $this->writeWorkerMarker($directory.'/save.go');
            $this->assertTrue($this->waitForMarker(
                $directory.'/save.locked',
                self::WORKER_TIMEOUT_MILLISECONDS,
                $save
            ));

            $revoke = $this->startWorker(
                $directory,
                'revoke',
                'save',
                [],
                31,
                35
            );
            $this->assertTrue($this->waitForMarker(
                $directory.'/revoke.ready',
                self::WORKER_TIMEOUT_MILLISECONDS,
                $revoke
            ));
            $this->writeWorkerMarker($directory.'/revoke.go');
            $revokeCompletedEarly = $this->waitForMarker(
                $directory.'/revoke.done',
                500,
                $revoke
            );

            $this->writeWorkerMarker($directory.'/save.release');
            $this->assertTrue($this->waitForMarker(
                $directory.'/save.done',
                self::WORKER_TIMEOUT_MILLISECONDS,
                $save
            ));
            $this->assertTrue($this->waitForMarker(
                $directory.'/revoke.done',
                self::WORKER_TIMEOUT_MILLISECONDS,
                $revoke
            ));

            $this->assertFalse(
                $revokeCompletedEarly,
                'Revoke bypassed the actor row held by the in-flight save.'
            );
            $this->assertWorkerSucceeded($directory, 'save');
            $this->assertWorkerSucceeded($directory, 'revoke');

            DB::purge('mysql');
            DB::reconnect('mysql');
            $this->assertSame(
                ['quotation.profit.view'],
                $this->permissionCodes()
            );
            $this->assertFalse(
                DB::table('user_permissions')
                    ->where('user_id', 35)
                    ->where('permission_code', 'permissions.manage')
                    ->exists()
            );
        } finally {
            @touch($directory.'/save.release');
            @touch($directory.'/save.go');
            @touch($directory.'/revoke.go');
            $this->stopWorkers([$save, $revoke]);
            $this->removeWorkerDirectory($directory);
        }
    }

    public function test_revoke_then_save_linearizes_and_later_save_is_denied(): void
    {
        $directory = $this->makeWorkerDirectory();
        $revoke = null;
        $save = null;

        try {
            $revoke = $this->startWorker(
                $directory,
                'revoke',
                'pause-after-user-lock',
                [],
                31,
                35
            );
            $this->assertTrue($this->waitForMarker(
                $directory.'/revoke.ready',
                self::WORKER_TIMEOUT_MILLISECONDS,
                $revoke
            ));
            $this->writeWorkerMarker($directory.'/revoke.go');
            $this->assertTrue($this->waitForMarker(
                $directory.'/revoke.locked',
                self::WORKER_TIMEOUT_MILLISECONDS,
                $revoke
            ));

            $save = $this->startWorker(
                $directory,
                'save',
                'save',
                ['quotation.profit.view'],
                35,
                40
            );
            $this->assertTrue($this->waitForMarker(
                $directory.'/save.ready',
                self::WORKER_TIMEOUT_MILLISECONDS,
                $save
            ));
            $this->writeWorkerMarker($directory.'/save.go');
            $saveFailedEarly = $this->waitForMarker(
                $directory.'/save.error',
                500,
                $save
            );

            $this->writeWorkerMarker($directory.'/revoke.release');
            $this->assertTrue($this->waitForMarker(
                $directory.'/revoke.done',
                self::WORKER_TIMEOUT_MILLISECONDS,
                $revoke
            ));
            $this->assertTrue($this->waitForMarker(
                $directory.'/save.error',
                self::WORKER_TIMEOUT_MILLISECONDS,
                $save
            ));

            $this->assertFalse(
                $saveFailedEarly,
                'Save did not wait for the actor-row revoke to commit.'
            );
            $this->assertWorkerSucceeded($directory, 'revoke');
            $this->assertWorkerAuthorizationDenied($directory, 'save');

            DB::purge('mysql');
            DB::reconnect('mysql');
            $this->assertSame([], $this->permissionCodes());
            $this->assertFalse(
                DB::table('user_permissions')
                    ->where('user_id', 35)
                    ->where('permission_code', 'permissions.manage')
                    ->exists()
            );
        } finally {
            @touch($directory.'/revoke.release');
            @touch($directory.'/revoke.go');
            @touch($directory.'/save.go');
            $this->stopWorkers([$revoke, $save]);
            $this->removeWorkerDirectory($directory);
        }
    }

    public function test_audit_uses_the_actor_snapshot_loaded_under_the_user_lock(): void
    {
        $staleActor = Users::findOrFail(35);
        DB::table('users')
            ->where('id', 35)
            ->update(['account' => 'fresh-manager']);

        $this->service()->savePermissions(
            $staleActor,
            Users::findOrFail(40),
            ['quotation.profit.view']
        );

        $this->assertSame(
            'fresh-manager',
            DB::table('permission_change_logs')
                ->where('target_user_id', 40)
                ->value('operator_account')
        );
    }

    public function test_audit_uses_the_target_snapshot_loaded_under_the_user_lock(): void
    {
        $staleTarget = Users::findOrFail(40);
        DB::table('users')->where('id', 40)->update(['account' => 'fresh-target']);

        $this->service()->savePermissions(
            Users::findOrFail(31),
            $staleTarget,
            ['quotation.profit.view']
        );

        $this->assertSame(
            'fresh-target',
            DB::table('permission_change_logs')
                ->where('target_user_id', 40)
                ->value('target_account')
        );
    }

    private function service(): PermissionService
    {
        return app(PermissionService::class);
    }

    private function permissionCodes(): array
    {
        return DB::table('user_permissions')
            ->where('user_id', 40)
            ->orderBy('permission_code')
            ->pluck('permission_code')
            ->all();
    }

    private function auditCodes(string $action): array
    {
        return DB::table('permission_change_logs')
            ->where('target_user_id', 40)
            ->where('action', $action)
            ->orderBy('permission_code')
            ->pluck('permission_code')
            ->all();
    }

    private function insertGrant(string $permissionCode): void
    {
        DB::table('user_permissions')->insert([
            'user_id' => 40,
            'permission_code' => $permissionCode,
            'granted_by' => 31,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function redis(): \Redis
    {
        return RedisService::getInstance(1);
    }

    private function addForcedAuditFailureConstraint(): void
    {
        DB::statement(
            "ALTER TABLE permission_change_logs
             ADD CONSTRAINT permission_logs_forced_failure_check
             CHECK (permission_code <> 'users.view')"
        );
    }

    private function dropForcedAuditFailureConstraint(): void
    {
        $constraintExists = DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', config('database.connections.mysql.database'))
            ->where('TABLE_NAME', 'permission_change_logs')
            ->where('CONSTRAINT_NAME', 'permission_logs_forced_failure_check')
            ->exists();

        if ($constraintExists) {
            DB::statement(
                'ALTER TABLE permission_change_logs '
                .'DROP CHECK permission_logs_forced_failure_check'
            );
        }
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
            && (string) $mysql['unix_socket'] === ''
            && (string) config('database.redis.default.url') === ''
            && config('database.redis.default.host') === 'redis'
            && (string) config('database.redis.default.port') === '6379';

        if (!$isIsolated) {
            throw new RuntimeException(
                'Refusing destructive permission transaction test outside isolated services.'
            );
        }
    }

    private function assertNoPermissionWorkerResidue(): void
    {
        $directories = glob(
            sys_get_temp_dir().'/permission-concurrency-*',
            GLOB_ONLYDIR
        ) ?: [];
        $this->assertSame(
            [],
            $directories,
            'Orphaned permission concurrency directories: '
                .implode(', ', $directories)
        );

        if (is_dir('/proc')) {
            $workers = [];
            foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $path) {
                $command = @file_get_contents($path);
                if (
                    is_string($command)
                    && strpos($command, '--permission-worker') !== false
                ) {
                    $workers[] = str_replace("\0", ' ', $command);
                }
            }
            $this->assertSame(
                [],
                $workers,
                'Orphaned permission worker processes: '.implode(' | ', $workers)
            );
        }

        try {
            $waiting = (int) DB::selectOne(
                'SELECT COUNT(*) AS aggregate '
                .'FROM performance_schema.data_lock_waits'
            )->aggregate;
            $this->assertSame(0, $waiting, 'MySQL has pending data lock waits.');
        } catch (QueryException $exception) {
            $waitingProcesses = array_values(array_filter(
                DB::select('SHOW PROCESSLIST'),
                function ($process): bool {
                    $state = (string) ($process->State ?? '');

                    return preg_match('/lock|waiting for/i', $state) === 1;
                }
            ));
            $this->assertSame(
                [],
                $waitingProcesses,
                'MySQL SHOW PROCESSLIST reports a lock wait.'
            );
        }

        $logPath = base_path('storage/logs/laravel.log');
        $this->assertFalse(
            file_exists($logPath),
            'Permission worker unexpectedly created '.$logPath
        );
    }

    private function makeWorkerDirectory(): string
    {
        $directory = sys_get_temp_dir().'/permission-concurrency-'
            .getmypid().'-'.str_replace('.', '', uniqid('', true));

        if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create concurrency test directory.');
        }

        return $directory;
    }

    private function startWorker(
        string $directory,
        string $name,
        string $mode,
        array $requested,
        int $actorId = 31,
        int $targetId = 40
    ): array {
        $arguments = [
            PHP_BINARY,
            __FILE__,
            '--permission-worker',
            $mode,
            $directory,
            $name,
            base64_encode(json_encode($requested)),
            (string) $actorId,
            (string) $targetId,
        ];
        $command = implode(' ', array_map('escapeshellarg', $arguments));
        if (DIRECTORY_SEPARATOR === '/') {
            $command = 'exec '.$command;
        }
        $pipes = [];
        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            base_path()
        );

        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start permission concurrency worker.');
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return [
            'process' => $process,
            'pipes' => $pipes,
            'directory' => $directory,
            'name' => $name,
            'stdout' => '',
            'stderr' => '',
            'status' => null,
        ];
    }

    private function waitForMarker(
        string $path,
        int $timeoutMilliseconds,
        ?array &$worker = null
    ): bool {
        $deadline = microtime(true) + ($timeoutMilliseconds / 1000);

        while (microtime(true) < $deadline) {
            clearstatcache(true, $path);
            if (file_exists($path)) {
                return true;
            }
            if ($worker !== null) {
                $this->readWorkerPipes($worker);
                $worker['status'] = proc_get_status($worker['process']);
                if (!$worker['status']['running']) {
                    $this->readWorkerPipes($worker);
                    throw new RuntimeException(
                        'Permission worker exited before marker '.$path.'. '
                        .$this->workerDiagnostics($worker)
                    );
                }
            }
            usleep(10000);
        }

        clearstatcache(true, $path);
        if (file_exists($path)) {
            return true;
        }
        if ($worker !== null) {
            $this->readWorkerPipes($worker);
            $worker['status'] = proc_get_status($worker['process']);
            if (!$worker['status']['running']) {
                throw new RuntimeException(
                    'Permission worker exited before marker '.$path.'. '
                    .$this->workerDiagnostics($worker)
                );
            }
        }

        return false;
    }

    private function writeWorkerMarker(string $path): void
    {
        if (!is_dir(dirname($path)) || !touch($path)) {
            throw new RuntimeException('Unable to write worker marker '.$path);
        }
    }

    private function assertWorkerSucceeded(string $directory, string $name): void
    {
        $errorPath = $directory.'/'.$name.'.error';
        clearstatcache(true, $errorPath);

        $this->assertFalse(
            file_exists($errorPath),
            file_exists($errorPath) ? file_get_contents($errorPath) : ''
        );
    }

    private function assertWorkerAuthorizationDenied(
        string $directory,
        string $name
    ): void {
        $error = file_get_contents($directory.'/'.$name.'.error');
        $this->assertStringStartsWith(
            AuthorizationException::class.':',
            $error,
            $error
        );
    }

    private function readWorkerPipes(array &$worker): void
    {
        foreach (['stdout' => 1, 'stderr' => 2] as $field => $index) {
            if (!isset($worker['pipes'][$index])
                || !is_resource($worker['pipes'][$index])
            ) {
                continue;
            }

            $output = stream_get_contents($worker['pipes'][$index]);
            if (is_string($output) && $output !== '') {
                $worker[$field] .= $output;
            }
        }
    }

    private function workerDiagnostics(array &$worker): string
    {
        $this->readWorkerPipes($worker);
        $status = $worker['status'];
        if (
            is_resource($worker['process'])
            && (!is_array($status) || !empty($status['running']))
        ) {
            $status = proc_get_status($worker['process']);
            $worker['status'] = $status;
        }
        $errorPath = $worker['directory'].'/'.$worker['name'].'.error';
        $error = file_exists($errorPath)
            ? trim((string) file_get_contents($errorPath))
            : '(no error marker)';

        return sprintf(
            'worker=%s pid=%s running=%s exitcode=%s termsig=%s '
                .'error=%s stdout=%s stderr=%s',
            $worker['name'],
            $status['pid'] ?? 'unknown',
            !empty($status['running']) ? 'yes' : 'no',
            $status['exitcode'] ?? 'unknown',
            $status['termsig'] ?? 'unknown',
            $error,
            trim($worker['stdout']) ?: '(empty)',
            trim($worker['stderr']) ?: '(empty)'
        );
    }

    private function waitForProcessExit(
        array &$worker,
        int $timeoutMilliseconds
    ): bool {
        $deadline = microtime(true) + ($timeoutMilliseconds / 1000);
        do {
            $this->readWorkerPipes($worker);
            $worker['status'] = proc_get_status($worker['process']);
            if (!$worker['status']['running']) {
                return true;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);

        $this->readWorkerPipes($worker);
        $worker['status'] = proc_get_status($worker['process']);

        return !$worker['status']['running'];
    }

    private function stopWorkers(array $workers): void
    {
        $failures = [];
        foreach ($workers as &$worker) {
            try {
                $this->stopWorker($worker);
            } catch (\Throwable $exception) {
                $failures[] = $exception->getMessage();
            }
        }
        unset($worker);

        if ($failures !== []) {
            throw new RuntimeException(implode(' | ', $failures));
        }
    }

    private function stopWorker(?array &$worker): void
    {
        if (
            $worker === null
            || !isset($worker['process'])
            || !is_resource($worker['process'])
        ) {
            return;
        }

        if (!$this->waitForProcessExit($worker, 2000)) {
            proc_terminate($worker['process']);
            if (!$this->waitForProcessExit($worker, 3000)) {
                proc_terminate($worker['process'], 9);
                if (!$this->waitForProcessExit($worker, 3000)) {
                    throw new RuntimeException(
                        'Unable to stop permission worker. '
                        .$this->workerDiagnostics($worker)
                    );
                }
            }
        }

        $this->readWorkerPipes($worker);
        foreach ($worker['pipes'] as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($worker['process']);
        $worker['process'] = null;
    }

    private function removeWorkerDirectory(string $directory): void
    {
        foreach (glob($directory.'/*') ?: [] as $path) {
            if (!unlink($path)) {
                throw new RuntimeException('Unable to remove worker marker '.$path);
            }
        }
        if (!rmdir($directory)) {
            throw new RuntimeException(
                'Unable to remove permission worker directory '.$directory
            );
        }
    }
}

function runPermissionTransactionWorker(array $arguments): void
{
    forcePermissionWorkerIsolationEnvironment();

    require_once dirname(__DIR__, 2).'/vendor/autoload.php';
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();

    list(
        $mode,
        $directory,
        $name,
        $encodedRequested,
        $actorId,
        $targetId
    ) = $arguments;
    $requested = json_decode(base64_decode($encodedRequested), true);

    try {
        assertPermissionWorkerIsolation();
        DB::purge('mysql');
        DB::reconnect('mysql');
        writePermissionWorkerMarker($directory.'/'.$name.'.ready');
        waitForPermissionWorkerMarker($directory.'/'.$name.'.go');

        if (
            $mode === 'pause-after-grant-lock'
            || $mode === 'pause-after-user-lock'
        ) {
            $paused = false;
            DB::listen(function (QueryExecuted $query) use (
                &$paused,
                $directory,
                $name,
                $mode
            ): void {
                $sql = strtolower($query->sql);
                $table = $mode === 'pause-after-user-lock'
                    ? 'from `users`'
                    : 'user_permissions';
                if (
                    !$paused
                    && strpos($sql, $table) !== false
                    && strpos($sql, 'for update') !== false
                ) {
                    $paused = true;
                    writePermissionWorkerMarker(
                        $directory.'/'.$name.'.locked'
                    );
                    waitForPermissionWorkerMarker($directory.'/'.$name.'.release');
                }
            });
        }

        $actor = Users::findOrFail((int) $actorId);
        $target = Users::findOrFail((int) $targetId);
        if ($mode === 'pause-after-authorization') {
            if (!$actor->hasPermission('permissions.manage')) {
                throw new AuthorizationException(
                    'Worker actor was not authorized before the transaction.'
                );
            }
            writePermissionWorkerMarker($directory.'/'.$name.'.authorized');
            waitForPermissionWorkerMarker($directory.'/'.$name.'.release');
        }

        app(PermissionService::class)->savePermissions(
            $actor,
            $target,
            $requested
        );

        writePermissionWorkerMarker($directory.'/'.$name.'.done');
        exit(0);
    } catch (\Throwable $exception) {
        if (DB::connection()->transactionLevel() > 0) {
            DB::rollBack();
        }
        $message = get_class($exception).': '.$exception->getMessage();
        $errorPath = $directory.'/'.$name.'.error';
        $written = is_dir($directory)
            && @file_put_contents($errorPath, $message) !== false;
        if (!$written) {
            fwrite(STDERR, $message.PHP_EOL);
        }
        exit(1);
    }
}

function writePermissionWorkerMarker(string $path): void
{
    if (!is_dir(dirname($path)) || !@touch($path)) {
        throw new RuntimeException('Unable to write worker marker '.$path);
    }
}

function forcePermissionWorkerIsolationEnvironment(): void
{
    $environment = [
        'APP_ENV' => 'testing',
        'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
        'LOG_CHANNEL' => 'test_null',
        'DATABASE_URL' => '',
        'DB_CONNECTION' => 'mysql',
        'DB_HOST' => 'mysql',
        'DB_PORT' => '3306',
        'DB_DATABASE' => 'tool_permissions_test',
        'DB_USERNAME' => 'test_runner',
        'DB_PASSWORD' => 'test-runner-password',
        'DB_SOCKET' => '',
        'REDIS_URL' => '',
        'REDIS_HOST' => 'redis',
        'REDIS_PORT' => '6379',
        'REDIS_PASSWORD' => '',
        'PERMISSION_ROOT_USER_ID' => '31',
        'VIEW_COMPILED_PATH' => '/tmp',
    ];

    foreach ($environment as $name => $value) {
        putenv($name.'='.$value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}

function assertPermissionWorkerIsolation(): void
{
    $mysql = config('database.connections.mysql');
    $isolated = app()->environment('testing')
        && env('LOG_CHANNEL') === 'test_null'
        && config('logging.default') === 'test_null'
        && config('database.default') === 'mysql'
        && (string) $mysql['url'] === ''
        && $mysql['host'] === 'mysql'
        && (string) $mysql['port'] === '3306'
        && $mysql['database'] === 'tool_permissions_test'
        && $mysql['username'] === 'test_runner'
        && (string) $mysql['unix_socket'] === ''
        && (string) config('database.redis.default.url') === ''
        && config('database.redis.default.host') === 'redis'
        && (string) config('database.redis.default.port') === '6379';

    if (!$isolated) {
        throw new RuntimeException(
            'Refusing permission concurrency worker outside isolated services.'
        );
    }
}

function waitForPermissionWorkerMarker(string $path): void
{
    $deadline = microtime(true) + PERMISSION_WORKER_TIMEOUT_SECONDS;

    while (microtime(true) < $deadline) {
        clearstatcache(true, $path);
        if (file_exists($path)) {
            return;
        }
        usleep(10000);
    }

    throw new RuntimeException('Timed out waiting for worker release marker.');
}
