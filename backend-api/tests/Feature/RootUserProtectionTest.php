<?php

namespace Tests\Feature;

use App\Service\RedisService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class RootUserProtectionTest extends TestCase
{
    private const ROOT_USER_ID = 31;
    private const MANAGER_USER_ID = 40;
    private const TARGET_USER_ID = 50;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertIsolatedServiceTargets();
        $this->clearTestTokens();
        $this->createSystemLogFixture();
        DB::table('permission_change_logs')->delete();
        DB::table('user_permissions')->delete();
        DB::table('users')->delete();

        DB::table('users')->insert([
            [
                'id' => self::ROOT_USER_ID,
                'account' => 'root-admin',
                'pwd' => 'root-password',
                'status' => 1,
                'expired_at' => '2026-12-31 23:59:59',
                'is_admin' => 1,
                'remark' => 'root-remark',
                'block_platform' => null,
            ],
            [
                'id' => self::MANAGER_USER_ID,
                'account' => 'legacy-admin-with-grants',
                'pwd' => 'manager-password',
                'status' => 1,
                'expired_at' => '2026-11-30 23:59:59',
                'is_admin' => 1,
                'remark' => 'manager-remark',
                'block_platform' => null,
            ],
            [
                'id' => self::TARGET_USER_ID,
                'account' => 'ordinary-target',
                'pwd' => 'target-password',
                'status' => 1,
                'expired_at' => '2026-10-31 23:59:59',
                'is_admin' => 0,
                'remark' => 'target-remark',
                'block_platform' => null,
            ],
        ]);

        foreach ([
            'users.view',
            'users.edit',
            'users.renew',
            'users.force_logout',
        ] as $permissionCode) {
            $this->insertGrant(self::ROOT_USER_ID, $permissionCode);
            $this->insertGrant(self::MANAGER_USER_ID, $permissionCode);
        }
    }

    protected function tearDown(): void
    {
        $this->clearTestTokens();
        Schema::dropIfExists('system_log');

        parent::tearDown();
    }

    public function test_non_root_cannot_change_the_root_password(): void
    {
        $before = DB::table('users')->where('id', self::ROOT_USER_ID)->first();

        $response = $this->postJson(
            '/api/admin/edit_user',
            [
                'id' => self::ROOT_USER_ID,
                'status' => 1,
                'pwd' => 'changed-password',
                'block_platform' => '2,4',
            ],
            $this->authHeaders(self::MANAGER_USER_ID)
        );

        $this->assertRootProtectionResponse($response);
        $this->assertEquals(
            $before,
            DB::table('users')->where('id', self::ROOT_USER_ID)->first()
        );
    }

    /**
     * @dataProvider rootStatusProvider
     */
    public function test_non_root_cannot_block_or_unblock_the_root(int $status): void
    {
        DB::table('users')->where('id', self::ROOT_USER_ID)->update([
            'status' => $status === 1 ? 2 : 1,
        ]);
        $before = DB::table('users')->where('id', self::ROOT_USER_ID)->first();

        $response = $this->postJson(
            '/api/admin/edit_user',
            [
                'id' => self::ROOT_USER_ID,
                'status' => $status,
                'block_platform' => null,
            ],
            $this->authHeaders(self::MANAGER_USER_ID)
        );

        $this->assertRootProtectionResponse($response);
        $this->assertEquals(
            $before,
            DB::table('users')->where('id', self::ROOT_USER_ID)->first()
        );
    }

    public function rootStatusProvider(): array
    {
        return [
            'block' => [2],
            'unblock' => [1],
        ];
    }

    public function test_non_root_cannot_change_the_root_remark(): void
    {
        $response = $this->postJson(
            '/api/user/remark',
            ['id' => self::ROOT_USER_ID, 'remark' => 'changed'],
            $this->authHeaders(self::MANAGER_USER_ID)
        );

        $this->assertRootProtectionResponse($response);
        $this->assertSame(
            'root-remark',
            DB::table('users')->where('id', self::ROOT_USER_ID)->value('remark')
        );
    }

    /**
     * @dataProvider singleRenewalProvider
     */
    public function test_non_root_cannot_renew_or_change_the_root_expiry(
        string $uri,
        array $payload
    ): void {
        $response = $this->postJson(
            $uri,
            array_merge(['id' => self::ROOT_USER_ID], $payload),
            $this->authHeaders(self::MANAGER_USER_ID)
        );

        $this->assertRootProtectionResponse($response);
        $this->assertSame(
            '2026-12-31 23:59:59',
            DB::table('users')
                ->where('id', self::ROOT_USER_ID)
                ->value('expired_at')
        );
    }

    public function singleRenewalProvider(): array
    {
        return [
            'renew by month' => [
                '/api/admin/expire_user',
                ['month' => 1],
            ],
            'set expiry date' => [
                '/api/admin/expire_date_user',
                ['date' => '2027-01-01 00:00:00'],
            ],
        ];
    }

    public function test_non_root_cannot_clear_the_root_token(): void
    {
        $rootToken = $this->issueToken(self::ROOT_USER_ID);

        $response = $this->postJson(
            '/api/admin/clear_token',
            ['id' => self::ROOT_USER_ID],
            $this->authHeaders(self::MANAGER_USER_ID)
        );

        $this->assertRootProtectionResponse($response);
        $this->assertSame(
            $rootToken,
            $this->redis()->get('user_token_'.self::ROOT_USER_ID)
        );
    }

    public function test_force_logout_clears_target_token_not_actor_token(): void
    {
        $actorToken = $this->issueToken(self::MANAGER_USER_ID);
        $this->issueToken(self::TARGET_USER_ID);

        $response = $this->postJson(
            '/api/admin/clear_token',
            ['id' => self::TARGET_USER_ID],
            ['X-Token' => $actorToken]
        );

        $response->assertStatus(200);
        $response->assertJson(['type' => 'ok', 'code' => 200]);
        $this->assertSame(
            $actorToken,
            $this->redis()->get('user_token_'.self::MANAGER_USER_ID)
        );
        $this->assertFalse(
            $this->redis()->get('user_token_'.self::TARGET_USER_ID)
        );
    }

    /**
     * @dataProvider invalidSingleTargetIdProvider
     */
    public function test_clear_token_rejects_non_canonical_target_id_without_side_effects(
        $invalidId
    ): void {
        $headers = $this->authHeaders(self::MANAGER_USER_ID);
        $this->issueToken(self::TARGET_USER_ID);
        $before = $this->mutationSnapshot();

        $response = $this->postJson(
            '/api/admin/clear_token',
            ['id' => $invalidId],
            $headers
        );

        $this->assertInvalidTargetResponse($response);
        $this->assertSame($before, $this->mutationSnapshot());
    }

    public function invalidSingleTargetIdProvider(): array
    {
        return [
            'boolean' => [true],
            'array' => [[self::TARGET_USER_ID]],
            'object' => [(object) ['value' => self::TARGET_USER_ID]],
            'float' => [50.5],
            'zero integer' => [0],
            'negative integer' => [-50],
            'empty string' => [''],
            'leading zero' => ['050'],
            'junk suffix' => ['50junk'],
            'decimal point string' => ['50.0'],
            'database integer overflow' => ['2147483648'],
            'overflow string' => [(string) PHP_INT_MAX.'0'],
        ];
    }

    /**
     * @dataProvider invalidSingleWriteProvider
     */
    public function test_single_user_write_rejects_invalid_target_before_side_effects(
        string $uri,
        array $payload
    ): void {
        $headers = $this->authHeaders(self::MANAGER_USER_ID);
        $before = $this->mutationSnapshot();

        $response = $this->postJson(
            $uri,
            $payload,
            $headers
        );

        $this->assertInvalidTargetResponse($response);
        $this->assertSame($before, $this->mutationSnapshot());
    }

    public function invalidSingleWriteProvider(): array
    {
        return [
            'edit junk suffix' => [
                '/api/admin/edit_user',
                [
                    'id' => '50junk',
                    'status' => 2,
                    'block_platform' => '2,4',
                ],
            ],
            'remark leading zero' => [
                '/api/user/remark',
                ['id' => '050', 'remark' => 'changed'],
            ],
            'month renewal float' => [
                '/api/admin/expire_user',
                ['id' => 50.5, 'month' => 1],
            ],
            'date renewal boolean' => [
                '/api/admin/expire_date_user',
                ['id' => true, 'date' => '2027-01-01 00:00:00'],
            ],
            'edit database integer overflow' => [
                '/api/admin/edit_user',
                [
                    'id' => '2147483648',
                    'status' => 2,
                    'block_platform' => '2,4',
                ],
            ],
            'remark database integer overflow' => [
                '/api/user/remark',
                ['id' => '2147483648', 'remark' => 'changed'],
            ],
            'month renewal database integer overflow' => [
                '/api/admin/expire_user',
                ['id' => '2147483648', 'month' => 1],
            ],
            'date renewal database integer overflow' => [
                '/api/admin/expire_date_user',
                [
                    'id' => '2147483648',
                    'date' => '2027-01-01 00:00:00',
                ],
            ],
        ];
    }

    /**
     * @dataProvider invalidBatchTargetIdProvider
     */
    public function test_batch_renewal_rejects_non_canonical_target_list_atomically(
        $invalidIds
    ): void {
        $headers = $this->authHeaders(self::MANAGER_USER_ID);
        $before = $this->mutationSnapshot();

        $response = $this->postJson(
            '/api/admin/expire_batch_user',
            ['id' => $invalidIds, 'month' => 1],
            $headers
        );

        $this->assertInvalidTargetResponse($response);
        $this->assertSame($before, $this->mutationSnapshot());
    }

    public function invalidBatchTargetIdProvider(): array
    {
        return [
            'boolean' => [true],
            'array' => [[self::TARGET_USER_ID]],
            'integer instead of string' => [self::TARGET_USER_ID],
            'empty item' => ['50,,31'],
            'trailing empty item' => ['50,'],
            'leading empty item' => [',50'],
            'leading zero item' => ['050,50'],
            'negative item' => ['50,-1'],
            'zero item' => ['50,0'],
            'junk item' => ['50,50junk'],
            'root-like junk item' => ['50,31junk'],
            'overflow item' => ['50,'.(string) PHP_INT_MAX.'0'],
        ];
    }

    public function test_batch_date_renewal_rejects_mixed_valid_invalid_targets_atomically(): void
    {
        $headers = $this->authHeaders(self::MANAGER_USER_ID);
        $before = $this->mutationSnapshot();

        $response = $this->postJson(
            '/api/admin/expire_batch_date_user',
            [
                'id' => self::TARGET_USER_ID.',50junk',
                'date' => '2027-01-01 00:00:00',
            ],
            $headers
        );

        $this->assertInvalidTargetResponse($response);
        $this->assertSame($before, $this->mutationSnapshot());
    }

    public function test_database_integer_max_is_accepted_before_missing_user_lookup(): void
    {
        $headers = $this->authHeaders(self::MANAGER_USER_ID);
        $this->issueToken(self::TARGET_USER_ID);
        $before = $this->mutationSnapshot();

        $response = $this->postJson(
            '/api/admin/clear_token',
            ['id' => '2147483647'],
            $headers
        );

        $this->assertMissingUserResponse($response);
        $this->assertSame($before, $this->mutationSnapshot());
    }

    /**
     * @dataProvider invalidMonthRenewalProvider
     */
    public function test_month_renewal_rejects_unapproved_values_before_locks_or_side_effects(
        string $uri,
        array $payload
    ): void {
        $headers = $this->authHeaders(self::MANAGER_USER_ID);
        $this->issueToken(self::ROOT_USER_ID);
        $this->issueToken(self::TARGET_USER_ID);
        $before = $this->mutationSnapshot();
        $lockingQueries = [];

        DB::listen(function (QueryExecuted $query) use (&$lockingQueries): void {
            if (strpos(strtolower($query->sql), 'for update') !== false) {
                $lockingQueries[] = $query->sql;
            }
        });

        $response = $this->postJson($uri, $payload, $headers);

        $this->assertInvalidMonthResponse($response);
        $this->assertSame([], $lockingQueries);
        $this->assertSame($before, $this->mutationSnapshot());
    }

    public function invalidMonthRenewalProvider(): array
    {
        $invalidValues = [
            'missing' => null,
            'zero' => 0,
            'negative' => -1,
            'float' => 1.5,
            'numeric string' => '3',
            'junk' => 'abc',
            'greater than twelve' => 13,
        ];
        $cases = [];

        foreach ([
            'single' => ['/api/admin/expire_user', self::TARGET_USER_ID],
            'batch' => ['/api/admin/expire_batch_user', (string) self::TARGET_USER_ID],
        ] as $scope => $endpoint) {
            foreach ($invalidValues as $label => $month) {
                $payload = ['id' => $endpoint[1]];
                if ($label !== 'missing') {
                    $payload['month'] = $month;
                }
                $cases[$scope.' '.$label] = [$endpoint[0], $payload];
            }
        }

        return $cases;
    }

    /**
     * @dataProvider approvedMonthRenewalProvider
     */
    public function test_month_renewal_accepts_only_approved_values(
        string $uri,
        $id,
        int $month,
        string $expectedExpiry
    ): void {
        DB::table('users')->where('id', self::TARGET_USER_ID)->update([
            'expired_at' => '2090-10-31 23:59:59',
        ]);
        $targetToken = $this->issueToken(self::TARGET_USER_ID);

        $response = $this->postJson(
            $uri,
            ['id' => $id, 'month' => $month],
            $this->authHeaders(self::MANAGER_USER_ID)
        );

        $response->assertStatus(200);
        $response->assertJson(['type' => 'ok', 'code' => 200]);
        $this->assertSame(
            $expectedExpiry,
            DB::table('users')
                ->where('id', self::TARGET_USER_ID)
                ->value('expired_at')
        );
        $this->assertSame(
            $month,
            DB::table('system_log')
                ->where('user_id', self::TARGET_USER_ID)
                ->count()
        );
        $this->assertSame(
            $targetToken,
            $this->redis()->get('user_token_'.self::TARGET_USER_ID)
        );
    }

    public function approvedMonthRenewalProvider(): array
    {
        $cases = [];
        $expectedExpiries = [
            1 => '2090-11-01 00:00:00',
            3 => '2091-01-01 00:00:00',
            6 => '2091-04-01 00:00:00',
            12 => '2091-10-01 00:00:00',
        ];

        foreach ([
            'single' => ['/api/admin/expire_user', self::TARGET_USER_ID],
            'batch' => ['/api/admin/expire_batch_user', (string) self::TARGET_USER_ID],
        ] as $scope => $endpoint) {
            foreach ($expectedExpiries as $month => $expectedExpiry) {
                $cases[$scope.' '.$month.' months'] = [
                    $endpoint[0],
                    $endpoint[1],
                    $month,
                    $expectedExpiry,
                ];
            }
        }

        return $cases;
    }

    /**
     * @dataProvider batchRenewalProvider
     */
    public function test_batch_renewal_rejects_database_integer_overflow_atomically(
        string $uri,
        array $payload
    ): void {
        $headers = $this->authHeaders(self::MANAGER_USER_ID);
        $this->issueToken(self::ROOT_USER_ID);
        $this->issueToken(self::TARGET_USER_ID);
        $before = $this->mutationSnapshot();

        $response = $this->postJson(
            $uri,
            array_merge([
                'id' => self::TARGET_USER_ID.',2147483648',
            ], $payload),
            $headers
        );

        $this->assertInvalidTargetResponse($response);
        $this->assertSame($before, $this->mutationSnapshot());
    }

    /**
     * @dataProvider batchRenewalProvider
     */
    public function test_batch_renewal_rejects_missing_canonical_target_atomically(
        string $uri,
        array $payload
    ): void {
        $headers = $this->authHeaders(self::MANAGER_USER_ID);
        $this->issueToken(self::ROOT_USER_ID);
        $this->issueToken(self::TARGET_USER_ID);
        $before = $this->mutationSnapshot();

        $response = $this->postJson(
            $uri,
            array_merge([
                'id' => self::TARGET_USER_ID.',999',
            ], $payload),
            $headers
        );

        $this->assertMissingUserResponse($response);
        $this->assertSame($before, $this->mutationSnapshot());
    }

    /**
     * @dataProvider batchRenewalProvider
     */
    public function test_batch_renewal_deduplicates_and_locks_targets_before_writes(
        string $uri,
        array $payload
    ): void {
        $headers = $this->authHeaders(self::MANAGER_USER_ID);
        $targetToken = $this->issueToken(self::TARGET_USER_ID);
        $targetQueries = [];

        DB::listen(function (QueryExecuted $query) use (&$targetQueries): void {
            $sql = strtolower($query->sql);
            if (
                strpos($sql, 'from `users`') !== false
                && strpos($sql, 'where `id` in') !== false
            ) {
                $targetQueries[] = [
                    'sql' => $sql,
                    'bindings' => $query->bindings,
                ];
            }
        });

        $response = $this->postJson(
            $uri,
            array_merge([
                'id' => self::TARGET_USER_ID.','.self::TARGET_USER_ID,
            ], $payload),
            $headers
        );

        $response->assertStatus(200);
        $response->assertJson(['type' => 'ok', 'code' => 200]);
        $this->assertCount(1, $targetQueries);
        $this->assertStringContainsString(
            'for update',
            $targetQueries[0]['sql']
        );
        $this->assertSame(
            [self::TARGET_USER_ID],
            array_map('intval', $targetQueries[0]['bindings'])
        );
        $this->assertSame(
            1,
            DB::table('system_log')
                ->where('user_id', self::TARGET_USER_ID)
                ->count()
        );
        $this->assertSame(
            $targetToken,
            $this->redis()->get('user_token_'.self::TARGET_USER_ID)
        );
    }

    public function test_canonical_decimal_string_target_remains_allowed(): void
    {
        $response = $this->postJson(
            '/api/admin/edit_user',
            [
                'id' => (string) self::TARGET_USER_ID,
                'status' => 2,
                'block_platform' => null,
            ],
            $this->authHeaders(self::MANAGER_USER_ID)
        );

        $response->assertStatus(200);
        $this->assertSame(
            2,
            (int) DB::table('users')
                ->where('id', self::TARGET_USER_ID)
                ->value('status')
        );
    }

    public function test_canonical_root_string_still_uses_root_protection(): void
    {
        $response = $this->postJson(
            '/api/user/remark',
            ['id' => (string) self::ROOT_USER_ID, 'remark' => 'changed'],
            $this->authHeaders(self::MANAGER_USER_ID)
        );

        $this->assertRootProtectionResponse($response);
        $this->assertSame(
            'root-remark',
            DB::table('users')->where('id', self::ROOT_USER_ID)->value('remark')
        );
    }

    /**
     * @dataProvider batchRenewalProvider
     */
    public function test_batch_containing_root_is_rejected_atomically(
        string $uri,
        array $payload
    ): void {
        $before = DB::table('users')
            ->whereIn('id', [self::ROOT_USER_ID, self::TARGET_USER_ID])
            ->orderBy('id')
            ->pluck('expired_at', 'id')
            ->all();

        $response = $this->postJson(
            $uri,
            array_merge([
                'id' => self::TARGET_USER_ID.','.self::ROOT_USER_ID,
            ], $payload),
            $this->authHeaders(self::MANAGER_USER_ID)
        );

        $this->assertRootProtectionResponse($response);
        $this->assertSame(
            $before,
            DB::table('users')
                ->whereIn('id', [self::ROOT_USER_ID, self::TARGET_USER_ID])
                ->orderBy('id')
                ->pluck('expired_at', 'id')
                ->all()
        );
    }

    public function batchRenewalProvider(): array
    {
        return [
            'renew by month' => [
                '/api/admin/expire_batch_user',
                ['month' => 1],
            ],
            'set expiry date' => [
                '/api/admin/expire_batch_date_user',
                ['date' => '2027-01-01 00:00:00'],
            ],
        ];
    }

    public function test_root_can_mutate_its_own_account(): void
    {
        $response = $this->postJson(
            '/api/admin/edit_user',
            [
                'id' => self::ROOT_USER_ID,
                'status' => 1,
                'pwd' => 'root-new-password',
                'block_platform' => null,
            ],
            $this->authHeaders(self::ROOT_USER_ID)
        );

        $response->assertStatus(200);
        $response->assertJson(['type' => 'ok', 'code' => 200]);
        $this->assertSame(
            \App\Model\Users::makePassword('root-new-password'),
            DB::table('users')->where('id', self::ROOT_USER_ID)->value('pwd')
        );
    }

    public function test_user_list_adds_root_flag_without_changing_pagination_contract(): void
    {
        $response = $this->getJson(
            '/api/user/list?page=1&page_size=10',
            $this->authHeaders(self::MANAGER_USER_ID)
        );

        $response->assertStatus(200);
        $paginator = $response->json('data');
        $this->assertSame([
            'current_page',
            'data',
            'first_page_url',
            'from',
            'last_page',
            'last_page_url',
            'next_page_url',
            'path',
            'per_page',
            'prev_page_url',
            'to',
            'total',
        ], array_keys($paginator));

        $users = array_column($paginator['data'], null, 'id');
        $this->assertArrayHasKey('is_permission_root', $users[self::ROOT_USER_ID]);
        $this->assertArrayHasKey('is_permission_root', $users[self::MANAGER_USER_ID]);
        $this->assertArrayHasKey('is_permission_root', $users[self::TARGET_USER_ID]);
        $this->assertTrue($users[self::ROOT_USER_ID]['is_permission_root']);
        $this->assertFalse($users[self::MANAGER_USER_ID]['is_permission_root']);
        $this->assertFalse($users[self::TARGET_USER_ID]['is_permission_root']);

        foreach ($users as $user) {
            foreach ([
                'id',
                'pid',
                'account',
                'last_login_at',
                'created_at',
                'updated_at',
                'status',
                'expired_at',
                'is_admin',
                'remark',
                'block_platform',
                'status_text',
            ] as $legacyField) {
                $this->assertArrayHasKey($legacyField, $user);
            }
            $this->assertArrayNotHasKey('pwd', $user);
            $this->assertArrayNotHasKey('last_login_ip', $user);
        }
    }

    public function test_no_user_deletion_route_exists(): void
    {
        foreach (app('router')->getRoutes() as $route) {
            if (strpos($route->getActionName(), 'UserController@') !== false) {
                $this->assertNotContains('DELETE', $route->methods(), $route->uri());
            }
        }
    }

    private function insertGrant(int $userId, string $permissionCode): void
    {
        DB::table('user_permissions')->insert([
            'user_id' => $userId,
            'permission_code' => $permissionCode,
            'granted_by' => self::ROOT_USER_ID,
            'created_at' => '2026-07-20 00:00:00',
            'updated_at' => '2026-07-20 00:00:00',
        ]);
    }

    private function createSystemLogFixture(): void
    {
        Schema::dropIfExists('system_log');
        Schema::create('system_log', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedTinyInteger('type');
            $table->string('remark', 255);
            $table->integer('user_id')->nullable();
            $table->dateTime('created_at')->nullable();
        });
    }

    private function mutationSnapshot(): array
    {
        return [
            'users' => DB::table('users')
                ->orderBy('id')
                ->get()
                ->map(function ($row): array {
                    return (array) $row;
                })
                ->all(),
            'system_log' => DB::table('system_log')
                ->orderBy('id')
                ->get()
                ->map(function ($row): array {
                    return (array) $row;
                })
                ->all(),
            'actor_token' => $this->redis()->get(
                'user_token_'.self::MANAGER_USER_ID
            ),
            'target_token' => $this->redis()->get(
                'user_token_'.self::TARGET_USER_ID
            ),
            'root_token' => $this->redis()->get(
                'user_token_'.self::ROOT_USER_ID
            ),
        ];
    }

    private function authHeaders(int $userId): array
    {
        return ['X-Token' => $this->issueToken($userId)];
    }

    private function issueToken(int $userId): string
    {
        $token = 'permission-task-6-root-token-'.$userId;
        $redis = $this->redis();
        $redis->set($token, $userId);
        $redis->set('user_token_'.$userId, $token);

        return $token;
    }

    private function clearTestTokens(): void
    {
        $redis = $this->redis();

        foreach ([
            self::ROOT_USER_ID,
            self::MANAGER_USER_ID,
            self::TARGET_USER_ID,
        ] as $userId) {
            $redis->del('permission-task-6-root-token-'.$userId);
            $redis->del('user_token_'.$userId);
        }
    }

    private function redis(): \Redis
    {
        return RedisService::getInstance(1);
    }

    private function assertRootProtectionResponse($response): void
    {
        $response->assertStatus(403);
        $response->assertJson([
            'type' => 'error',
            'code' => 403,
            'message' => '根账号受保护',
        ]);
    }

    private function assertInvalidTargetResponse($response): void
    {
        $response->assertStatus(422);
        $response->assertJson([
            'type' => 'error',
            'code' => 422,
            'message' => '用户ID参数无效',
        ]);
    }

    private function assertInvalidMonthResponse($response): void
    {
        $response->assertStatus(422);
        $response->assertJson([
            'type' => 'error',
            'code' => 422,
            'message' => '月份参数无效',
        ]);
    }

    private function assertMissingUserResponse($response): void
    {
        $response->assertStatus(200);
        $response->assertJson([
            'type' => 'error',
            'code' => 460,
            'message' => '找不到该用户',
        ]);
    }

    private function assertIsolatedServiceTargets(): void
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
            && (string) config('database.redis.default.port') === '6379'
            && (string) config('database.redis.default.password') === '';

        if (!$isIsolated) {
            throw new RuntimeException(
                'Refusing destructive root-account test outside isolated services.'
            );
        }
    }
}
