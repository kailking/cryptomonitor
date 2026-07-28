<?php

namespace Tests\Unit;

use App\Service\Exchanges\OkexApi;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

class OkexCredentialConfigurationTest extends TestCase
{
    /**
     * @dataProvider incompleteCredentialProvider
     */
    public function test_authenticated_use_fails_closed_before_reaching_client_when_config_is_incomplete(
        array $credentials
    ): void {
        config()->set('services.okx', $credentials);
        $api = new InspectableOkexApi();

        try {
            $api->getCurrencyList();
            self::fail('Incomplete OKX configuration was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'OKX credentials are not configured',
                $exception->getMessage()
            );
        }

        $this->assertSame(0, $api->authenticatedFactoryCalls);
    }

    public function incompleteCredentialProvider(): array
    {
        return [
            'missing key' => [[
                'api_key' => '',
                'api_secret' => 'test-secret',
                'passphrase' => 'test-passphrase',
            ]],
            'missing secret' => [[
                'api_key' => 'test-key',
                'api_secret' => null,
                'passphrase' => 'test-passphrase',
            ]],
            'missing passphrase' => [[
                'api_key' => 'test-key',
                'api_secret' => 'test-secret',
                'passphrase' => '',
            ]],
        ];
    }

    public function test_get_currency_list_uses_authenticated_factory_after_validation(): void
    {
        $api = new InspectableOkexApi();
        if (!method_exists($api, 'authenticatedClient')) {
            self::fail('Authenticated client factory is missing.');
        }

        $method = new ReflectionMethod(OkexApi::class, 'getCurrencyList');
        $sourceLines = file($method->getFileName());
        $methodSource = implode('', array_slice(
            $sourceLines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));
        if (preg_match('/\bnew\s+OkexV5\s*\(/', $methodSource) === 1) {
            self::fail('getCurrencyList constructs the authenticated client directly.');
        }

        $credentials = [
            'api_key' => 'test-key',
            'api_secret' => 'test-secret',
            'passphrase' => 'test-passphrase',
        ];
        config()->set('services.okx', $credentials);

        $result = $api->getCurrencyList();

        $this->assertSame(1, $api->authenticatedFactoryCalls);
        $this->assertSame($credentials, $api->authenticatedFactoryCredentials);
        $this->assertSame(['data' => ['test-result']], $result);
    }

    public function test_current_tree_has_sanitized_okx_configuration_contract(): void
    {
        $projectRoot = dirname(__DIR__, 2);
        $excludedDirectories = ['.git', 'vendor', 'storage', 'node_modules'];
        $filesScanned = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator(
                    $projectRoot,
                    \FilesystemIterator::SKIP_DOTS
                ),
                function (\SplFileInfo $entry) use ($excludedDirectories): bool {
                    return !$entry->isDir()
                        || !in_array($entry->getFilename(), $excludedDirectories, true);
                }
            )
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $relativePath = str_replace('\\', '/', substr(
                $file->getPathname(),
                strlen($projectRoot) + 1
            ));
            if ($relativePath === '.env') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            if ($contents === false) {
                self::fail('Unable to scan '.$relativePath.'.');
            }
            if (strpos(substr($contents, 0, 8192), "\0") !== false) {
                continue;
            }

            $filesScanned[] = $relativePath;
            $this->assertNoSensitiveLiteral($relativePath, $contents);
        }

        foreach ([
            '.env.example',
            'bootstrap/app.php',
            'public/index.php',
            'resources/views/welcome.blade.php',
            'server.php',
        ] as $requiredPath) {
            $this->assertContains($requiredPath, $filesScanned);
        }

        $envLines = file(
            $projectRoot.DIRECTORY_SEPARATOR.'.env.example',
            FILE_IGNORE_NEW_LINES
        );
        foreach (['OKX_API_KEY=', 'OKX_API_SECRET=', 'OKX_PASSPHRASE='] as $placeholder) {
            if (!in_array($placeholder, $envLines, true)) {
                self::fail('Missing empty OKX placeholder in .env.example.');
            }
        }

        $services = file_get_contents(
            $projectRoot.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'services.php'
        );
        foreach (['OKX_API_KEY', 'OKX_API_SECRET', 'OKX_PASSPHRASE'] as $variable) {
            $pattern = '/env\(\''.$variable.'\'\)/';
            if (preg_match($pattern, $services) !== 1) {
                self::fail('Missing default-free OKX service mapping for '.$variable.'.');
            }
        }
    }

    private function assertNoSensitiveLiteral(
        string $relativePath,
        string $contents
    ): void {
        $name = '(?:api[_-]?key|api[_-]?secret|passphrase|OKX_API_KEY|OKX_API_SECRET|OKX_PASSPHRASE)';
        $pattern = '/(?:[\'\"]'.$name.'[\'\"]\s*(?:=>|:)|(?<![\'\"])\b'.$name.'\b\s*(?:=|:))\s*([\'\"])([^\'\"]*)\1/i';
        $isTestPath = strpos($relativePath, 'tests/') === 0;

        foreach (preg_split('/\R/', $contents) as $lineIndex => $line) {
            if (preg_match_all($pattern, $line, $matches, PREG_SET_ORDER) === 0) {
                continue;
            }

            foreach ($matches as $match) {
                $value = $match[2];
                if ($value === '') {
                    continue;
                }
                if (
                    $isTestPath
                    && preg_match('/^(?:test|fake|dummy|redacted)(?:[-_].*)?$/i', $value) === 1
                ) {
                    continue;
                }

                self::fail(sprintf(
                    'Sensitive literal detected at %s:%d; value suppressed.',
                    $relativePath,
                    $lineIndex + 1
                ));
            }
        }
    }
}

class InspectableOkexApi extends OkexApi
{
    public $authenticatedFactoryCalls = 0;
    public $authenticatedFactoryCredentials;

    protected function authenticatedClient(array $credentials)
    {
        $this->authenticatedFactoryCalls++;
        $this->authenticatedFactoryCredentials = $credentials;

        return new FakeAuthenticatedOkexClient();
    }
}

class FakeAuthenticatedOkexClient
{
    public function asset()
    {
        return $this;
    }

    public function getCurrencies(): array
    {
        return ['data' => ['test-result']];
    }
}
