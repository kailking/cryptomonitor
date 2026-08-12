<?php

namespace App\Console\Commands\withdraw_info;

use App\Model\CurrencyQuotation;
use App\Model\PlatformWithdraw;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Console\Command;

class UpdateCoinwWithdraw extends Command
{
    // 命令签名
    protected $signature = 'update_coinw_withdraw {--host=https://api.coinw.com} {--try=3} {--coin=}';
    protected $description = 'CoinW Spot: GET /api/v1/public?command=returnCurrencies 同步全局币种充提状态到 platform_withdraw';

    // CoinW 获取币种信息的固定 Path 和 Command
    private const PATH = '/api/v1/public';

    public function handle()
    {
        // 平台常量，对应你在 CurrencyQuotation 中定义的 PLATFORM_COINW (20)
        $platform = CurrencyQuotation::PLATFORM_COINW;

        $host = trim((string)$this->option('host'));
        $host = rtrim($host ?: 'https://api.coinw.com', '/');

        $tryMax = max(1, (int)$this->option('try'));
        $coinOpt = strtoupper(trim((string)$this->option('coin'))); // 可选：只拉某个币

        $client = new Client([
            'base_uri' => $host,
            'timeout' => 30,
            'connect_timeout' => 10,
            'http_errors' => false,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                // 伪装请求头，防止被 WAF 拦截
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0'
            ],
        ]);

        $query = ['command' => 'returnCurrencies'];
        $url = self::PATH . '?' . http_build_query($query);

        $resp = $this->requestJsonWithRetry($client, $url, $tryMax);
        if (!$resp['ok']) {
            $this->error('请求 CoinW 失败: ' . $resp['err']);
            return 2;
        }

        $json = $resp['json'];
        if (!is_array($json)) {
            $this->error('CoinW 返回不是 JSON');
            return 3;
        }

        // CoinW 成功状态码为字符串 "200"
        $code = (string)($json['code'] ?? '');
        if ($code !== '' && $code !== '200') {
            $this->error('CoinW 返回 code != 200: ' . $code . ' msg=' . (string)($json['msg'] ?? ''));
            return 4;
        }

        $data = $json['data'] ?? null;
        if (!is_array($data) || empty($data)) {
            $this->warn('CoinW 返回无 data 或 data 为空。');
            return 0;
        }

        $updated = 0;
        $skipped = 0;

        foreach ($data as $coinKey => $item) {
            if (!is_array($item)) { $skipped++; continue; }

            $coin = isset($item['symbol']) ? strtoupper(trim((string)$item['symbol'])) : strtoupper($coinKey);
            if (!$coin) { $skipped++; continue; }

            // 如果命令行指定了单一币种，过滤其他币种
            if ($coinOpt !== '' && $coin !== $coinOpt) {
                continue;
            }

            // CoinW 的充提状态在币种级别
            $withdrawable  = $item['withDraw'] ?? null;
            $rechargeable  = $item['recharge'] ?? null;

            if ($withdrawable === null || $rechargeable === null) { $skipped++; continue; }

            $isWithdraw = $this->to01($withdrawable);
            $isDeposit  = $this->to01($rechargeable);

            // 🚀 核心修改：直接放弃处理链信息，将 network 设为 null
            $network = null;
            $withdrawFee = null;
            $withdrawPrecision = null; 
            $confirmNum = null;

            // 写入 platform_withdraw，每个币种只有一条记录
            $ok = PlatformWithdraw::updateRecord(
                $coin, 
                $platform, 
                $network, 
                $isWithdraw, 
                $isDeposit,
                $withdrawFee,        // 暂无数据
                $withdrawPrecision,  // 暂无数据
                $confirmNum          // 暂无数据
            );
            
            if ($ok) $updated++;
        }

        $this->info("CoinW done. updated={$updated}, skipped={$skipped}");
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