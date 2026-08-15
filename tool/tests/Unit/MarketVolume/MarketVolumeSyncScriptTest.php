<?php

namespace Tests\Unit\MarketVolume;

use PHPUnit\Framework\TestCase;

class MarketVolumeSyncScriptTest extends TestCase
{
    private $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->temporaryDirectories) as $directory) {
            $this->removeDirectory($directory);
        }

        parent::tearDown();
    }

    public function testScriptDeclaresProductionDefaultsAndDiscoversActivePlatformsFromArtisan()
    {
        $script = file_get_contents($this->scriptPath());

        $this->assertStringContainsString('MARKET_VOLUME_PHP_BIN:-/usr/bin/php', $script);
        $this->assertStringContainsString('MARKET_VOLUME_TOOL_DIR:-/www/wwwroot/tool', $script);
        $this->assertStringContainsString('MARKET_VOLUME_STAGGER_SECONDS:-30', $script);
        $this->assertStringContainsString('MARKET_VOLUME_TASK_TIMEOUT_SECONDS:-120', $script);
        $this->assertStringContainsString('MARKET_VOLUME_TASK_KILL_AFTER_SECONDS:-10', $script);
        $this->assertStringContainsString('market-volume:sync --list-platforms --no-interaction', $script);
        $this->assertStringNotContainsString('"1|htx"', $script);
        $this->assertTrue(is_executable($this->scriptPath()));
    }

    public function testRunnerStartsOneIsolatedCommandPerPlatformWithoutNetworkAccess()
    {
        $fixture = $this->createFixture();
        $result = $this->runScript($fixture, [
            'MARKET_VOLUME_STAGGER_SECONDS' => '0',
        ]);

        $this->assertSame(0, $result['exit_code'], $result['output']);
        $invocations = file($fixture['invocations'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        sort($invocations, SORT_NATURAL);

        $expected = [];
        foreach ([1, 2, 3, 4, 5, 8, 9, 10, 15, 16, 19, 21, 22, 23] as $platformId) {
            $expected[] = $fixture['artisan'].' market-volume:sync --platform='.$platformId.' --no-interaction';
        }
        sort($expected, SORT_NATURAL);

        $this->assertSame($expected, $invocations);
        $this->assertStringContainsString('succeeded=14 failed=0 total=14', file_get_contents($fixture['log']));
    }

    public function testNonBlockingRoundLockSkipsBeforeStartingAnyPlatform()
    {
        $fixture = $this->createFixture();
        file_put_contents($fixture['flock'], "#!/usr/bin/env bash\nexit 1\n");
        chmod($fixture['flock'], 0700);

        $result = $this->runScript($fixture, [
            'MARKET_VOLUME_STAGGER_SECONDS' => '0',
        ]);

        $this->assertSame(0, $result['exit_code'], $result['output']);
        $this->assertFileNotExists($fixture['invocations']);
        $this->assertStringContainsString('still running; skipped', file_get_contents($fixture['log']));
    }

    public function testOnePlatformFailureDoesNotPreventTheRemainingPlatformsAndReturnsFailure()
    {
        $fixture = $this->createFixture();
        $result = $this->runScript($fixture, [
            'MARKET_VOLUME_TEST_FAIL_PLATFORM' => '5',
            'MARKET_VOLUME_TEST_FAIL_EXIT_CODE' => '124',
        ]);

        $this->assertSame(1, $result['exit_code'], $result['output']);
        $invocations = file($fixture['invocations'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertCount(14, $invocations);

        $log = file_get_contents($fixture['log']);
        $this->assertStringContainsString('platform=5', $log);
        $this->assertStringContainsString('failed exit_code=124', $log);
        $this->assertStringContainsString('succeeded=13 failed=1 total=14', $log);
    }

    /**
     * @dataProvider invalidPlatformListProvider
     */
    public function testInvalidPlatformDiscoveryFailsBeforeAnyCollectionRequest($platformList)
    {
        $fixture = $this->createFixture();
        $result = $this->runScript($fixture, [
            'MARKET_VOLUME_TEST_PLATFORM_LIST' => $platformList,
        ]);

        $this->assertSame(65, $result['exit_code'], $result['output']);
        $this->assertFileNotExists($fixture['invocations']);
    }

    public function invalidPlatformListProvider()
    {
        return [
            'empty' => [''],
            'non numeric' => ["1\nnot-an-id\n2"],
            'duplicate' => ["1\n2\n1"],
            'leading zero' => ["1\n02"],
        ];
    }

    public function testScriptTracksChildrenAndDoesNotUseBroadProcessTermination()
    {
        $script = file_get_contents($this->scriptPath());

        $this->assertStringContainsString('PIDS+=("$pid")', $script);
        $this->assertStringContainsString('wait "$pid"', $script);
        $this->assertStringContainsString('signal_tracked_platform TERM "$pid"', $script);
        $this->assertStringContainsString('signal_tracked_platform KILL "$pid"', $script);
        $this->assertStringContainsString('exec "$TIMEOUT_BIN" -k', $script);
        $this->assertStringContainsString('kill "-${signal_name}" -- "-${tracked_pid}"', $script);
        $this->assertStringNotContainsString('timeout --foreground', $script);
        $this->assertStringNotContainsString('pkill', $script);
        $this->assertStringNotContainsString('killall', $script);
        $this->assertStringNotContainsString('nohup', $script);
        $this->assertStringNotContainsString('disown', $script);
    }

    private function scriptPath()
    {
        return dirname(__DIR__, 3).'/scripts/update_market_volume.sh';
    }

    private function createFixture()
    {
        $root = sys_get_temp_dir().'/market-volume-script-'.bin2hex(random_bytes(6));
        $tool = $root.'/tool';
        mkdir($tool, 0700, true);
        $this->temporaryDirectories[] = $root;

        $artisan = $tool.'/artisan';
        file_put_contents($artisan, "<?php\n");

        $invocations = $root.'/invocations.log';
        $php = $root.'/fake-php';
        file_put_contents($php, implode("\n", [
            '#!/usr/bin/env bash',
            'if [ "${3:-}" = "--list-platforms" ]; then',
            '    printf \'%s\\n\' "$MARKET_VOLUME_TEST_PLATFORM_LIST"',
            '    exit 0',
            'fi',
            'printf \'%s\\n\' "$*" >> "$MARKET_VOLUME_TEST_INVOCATIONS"',
            'for argument in "$@"; do',
            '    case "$argument" in',
            '        --platform=*) platform_id="${argument#--platform=}" ;;',
            '    esac',
            'done',
            'if [ "${platform_id:-}" = "${MARKET_VOLUME_TEST_FAIL_PLATFORM:-}" ]; then',
            '    exit "${MARKET_VOLUME_TEST_FAIL_EXIT_CODE:-2}"',
            'fi',
            'exit 0',
            '',
        ]));
        chmod($php, 0700);

        $timeout = $root.'/fake-timeout';
        file_put_contents($timeout, implode("\n", [
            '#!/usr/bin/env bash',
            'if [ "${1:-}" != "-k" ]; then exit 64; fi',
            'shift 2',
            'shift',
            'exec "$@"',
            '',
        ]));
        chmod($timeout, 0700);

        $flock = $root.'/fake-flock';
        file_put_contents($flock, "#!/usr/bin/env bash\nexit 0\n");
        chmod($flock, 0700);

        return [
            'root' => $root,
            'tool' => $tool,
            'artisan' => $artisan,
            'php' => $php,
            'flock' => $flock,
            'timeout' => $timeout,
            'invocations' => $invocations,
            'log' => $root.'/runner.log',
            'lock' => $root.'/runner.lock',
        ];
    }

    private function runScript(array $fixture, array $overrides = [])
    {
        $environment = array_merge($_ENV, [
            'PATH' => getenv('PATH'),
            'MARKET_VOLUME_PHP_BIN' => $fixture['php'],
            'MARKET_VOLUME_TOOL_DIR' => $fixture['tool'],
            'MARKET_VOLUME_STAGGER_SECONDS' => '0',
            'MARKET_VOLUME_LOG_FILE' => $fixture['log'],
            'MARKET_VOLUME_LOCK_FILE' => $fixture['lock'],
            'MARKET_VOLUME_FLOCK_BIN' => $fixture['flock'],
            'MARKET_VOLUME_TIMEOUT_BIN' => $fixture['timeout'],
            'MARKET_VOLUME_TEST_INVOCATIONS' => $fixture['invocations'],
            'MARKET_VOLUME_TEST_PLATFORM_LIST' => "1\n2\n3\n4\n5\n8\n9\n10\n15\n16\n19\n21\n22\n23",
        ], $overrides);

        $command = implode(' ', array_map('escapeshellarg', [
            '/usr/bin/env',
            'bash',
            $this->scriptPath(),
        ]));
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptorSpec, $pipes, null, $environment);
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return [
            'exit_code' => $exitCode,
            'output' => $stdout.$stderr,
        ];
    }

    private function removeDirectory($directory)
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($directory);
    }
}
