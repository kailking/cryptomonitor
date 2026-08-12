<?php

namespace App\Console\Commands\withdraw_info;

use App\Model\CurrencyQuotation;
use App\Model\PlatformWithdraw;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Console\Command;

class UpdateBitgetWithdraw extends Command
{
    protected $signature = 'update_bitget_withdraw {--host=https://api.bitget.com} {--try=3} {--coin=}';
    protected $description = 'Bitget Spot: GET /api/v2/spot/public/coins 同步币种链(网络)充提状态到 platform_withdraw';

    private const PATH = '/api/v2/spot/public/coins';

    public function handle()
    {
        // 你系统里 Bitget 的 platform 常量
        $platform = CurrencyQuotation::PLATFORM_BITGET;

        $host = trim((string)$this->option('host'));
        $host = rtrim($host ?: 'https://api.bitget.com', '/');

        $tryMax = max(1, (int)$this->option('try'));
        $coinOpt = trim((string)$this->option('coin')); // 可选：只拉某个币

        $client = new Client([
            'base_uri' => $host,
            'timeout' => 30,
            'connect_timeout' => 10,
            'http_errors' => false,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ]);

        $query = [];
        if ($coinOpt !== '') {
            $query['coin'] = strtoupper($coinOpt);
        }
        $url = self::PATH . ($query ? ('?' . http_build_query($query)) : '');

        $resp = $this->requestJsonWithRetry($client, $url, $tryMax);
        if (!$resp['ok']) {
            $this->error('请求 Bitget 失败: ' . $resp['err']);
            return 2;
        }

        $json = $resp['json'];
        if (!is_array($json)) {
            $this->error('Bitget 返回不是 JSON');
            return 3;
        }

        $code = (string)($json['code'] ?? '');
        if ($code !== '' && $code !== '00000') {
            $this->error('Bitget 返回 code != 00000: ' . $code . ' msg=' . (string)($json['msg'] ?? ''));
            return 4;
        }

        $data = $json['data'] ?? null;
        if (!is_array($data)) {
            $this->warn('Bitget 返回无 data 或 data 不是数组。');
            return 0;
        }

        $updated = 0;
        $skipped = 0;

        foreach ($data as $item) {
            if (!is_array($item)) { $skipped++; continue; }

            $coin = isset($item['coin']) ? strtoupper((string)$item['coin']) : null;
            if (!$coin) { $skipped++; continue; }

            $chains = $item['chains'] ?? [];
            if (!is_array($chains) || empty($chains)) {
                $skipped++;
                continue;
            }

            foreach ($chains as $ch) {
                if (!is_array($ch)) { $skipped++; continue; }

                $network = isset($ch['chain']) ? strtoupper(trim((string)$ch['chain'])) : null;
                $withdrawable  = $ch['withdrawable']  ?? null;
                $rechargeable  = $ch['rechargeable']  ?? null;

                if ($network === null || $network === '') { $skipped++; continue; }
                if ($withdrawable === null || $rechargeable === null) { $skipped++; continue; }

                $isWithdraw = $this->to01($withdrawable);
                $isDeposit  = $this->to01($rechargeable);

                // --- 根据 Bitget JSON 结构提取新增字段 ---
                $withdrawFee = $ch['withdrawFee'] ?? null;          // "0.005"
                $withdrawPrecision = $ch['withdrawMinScale'] ?? null; // "8"
                $confirmNum = $ch['depositConfirm'] ?? null;        // "1"
                // ---------------------------------------

                // 写入 platform_withdraw
                $ok = PlatformWithdraw::updateRecord(
                    $coin, 
                    $platform, 
                    $network, 
                    $isWithdraw, 
                    $isDeposit,
                    $withdrawFee,        // 新增字段
                    $withdrawPrecision,  // 新增字段
                    $confirmNum          // 新增字段
                );
                
                if ($ok) $updated++;
            }
        }

        $this->info("Bitget done. updated={$updated}, skipped={$skipped}");
        return 0;
    }

    private function requestJsonWithRetry(Client $client, string $url, int $tryMax): array
    {
        $lastErr = '';

        for ($i = 1; $i <= $tryMax; $i++) {
            try {
                $resp = $client->request('GET', $url);

                $code = (int)$resp->getStatusCode();
                $raw  = (string)$resp->getBody();

                if ($code < 200 || $code >= 300) {
                    $lastErr = "HTTP {$code}: " . mb_substr($raw, 0, 500);
                    sleep($i);
                    continue;
                }

                $json = json_decode($raw, true);
                if ($json === null && json_last_error() !== JSON_ERROR_NONE) {
                    $lastErr = 'JSON parse error: ' . json_last_error_msg() . ' raw=' . mb_substr($raw, 0, 200);
                    sleep($i);
                    continue;
                }

                return ['ok' => true, 'json' => $json, 'err' => ''];
            } catch (GuzzleException $e) {
                $lastErr = $e->getMessage();
                sleep($i);
            } catch (\Throwable $e) {
                $lastErr = $e->getMessage();
                sleep($i);
            }
        }

        return ['ok' => false, 'json' => null, 'err' => $lastErr];
    }

    private function to01($v): int
    {
        if (is_bool($v)) return $v ? 1 : 0;
        if (is_numeric($v)) return ((int)$v) === 1 ? 1 : 0;
        $s = strtolower(trim((string)$v));
        return in_array($s, ['1','true','yes','y','on'], true) ? 1 : 0;
    }
}