<?php

namespace App\Console\Commands\withdraw_info;

use App\Model\CurrencyQuotation;
use App\Model\PlatformWithdraw;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Console\Command;

class UpdateXTWithdraw extends Command
{
    protected $signature = 'update_xt_withdraw {--try=3}';
    protected $description = 'XT Spot: 同步币种链(网络)充提状态到 platform_withdraw（毫秒级拉闸防脏数据版）';

    // XT Public API - 获取支持的币种和网络信息
    private const BASE_URL = 'https://sapi.xt.com';
    private const PATH = '/v4/public/wallet/support/currency';

    public function handle()
    {
        // 确保你的 CurrencyQuotation 模型里定义了 PLATFORM_XT
        $platform = CurrencyQuotation::PLATFORM_XT;

        $tryMax = max(1, (int)$this->option('try'));

        $client = new Client([
            'base_uri' => self::BASE_URL,
            'timeout' => 30,
            'connect_timeout' => 10,
            'http_errors' => false,
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0',
            ],
        ]);

        $this->info("Fetching XT supported currencies...");
        $resp = $this->requestJsonWithRetry($client, self::PATH, $tryMax);

        if (!$resp['ok']) {
            $this->error('请求 XT 失败: ' . $resp['err']);
            return 1;
        }

        $json = $resp['json'];
        if (!is_array($json)) {
            $this->error('XT 返回不是 JSON');
            return 2;
        }

        // XT 成功状态码 rc 为 0
        $rc = $json['rc'] ?? -1;
        if ($rc !== 0) {
            $this->error('XT 返回 rc != 0: ' . $rc . ' msg=' . (string)($json['mc'] ?? ''));
            return 3;
        }

        $data = $json['result'] ?? null;
        if (!is_array($data)) {
            $this->warn('XT 返回无 result 或 result 不是数组。');
            return 0;
        }

        $ret = $this->updateFromData($data, $platform);

        $this->info("XT withdraw info done. updated={$ret['updated']}, skipped={$ret['skipped']}");
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

    private function updateFromData(array $data, int $platform): array
    {
        $updated = 0;
        $skipped = 0;

        foreach ($data as $item) {
            if (!is_array($item)) {
                $skipped++;
                continue;
            }

            // XT 接口中的币种字段名为 'currency'
            $currency = isset($item['currency']) ? strtoupper(trim((string)$item['currency'])) : null;
            if (!$currency) {
                $skipped++;
                continue;
            }

            // ==========================================
            // 🚀 核心防线 1：单币种毫秒级物理拉闸
            // 无论接口怎么返回，先把你所有的历史网络记录清零，专治偷偷下架的死链！
            // ==========================================
            PlatformWithdraw::where('platform', $platform)
                ->where('currency_name', $currency)
                ->update([
                    'is_withdraw' => 0,
                    'is_deposit'  => 0
                ]);

            // XT 接口中网络列表字段名为 'supportChains'
            $chains = $item['supportChains'] ?? [];

            // ==========================================
            // 🚀 核心防线 2：无链即判死刑，彻底无视外层假数据
            // ==========================================
            if (empty($chains) || !is_array($chains)) {
                
                $isWithdraw = 0;
                $isDeposit  = 0;

                // 写入数据库，network 传 null，明确记录这是一个“无链的死币”
                $ok = PlatformWithdraw::updateRecord(
                    $currency, 
                    $platform, 
                    null, 
                    $isWithdraw, 
                    $isDeposit,
                    '0',  
                    0,    
                    0     
                );

                if ($ok) $updated++; else $skipped++;

            } else {
                // ==========================================
                // 🚀 正常通道：多链嵌套解析，只激活真实活着的链
                // ==========================================
                foreach ($chains as $ch) {
                    if (!is_array($ch)) {
                        $skipped++;
                        continue;
                    }

                    // 提取网络名称
                    $rawNetworkField = isset($ch['chain']) ? trim((string)$ch['chain']) : '';
                    if (preg_match('/\((.*?)\)/', $rawNetworkField, $matches)) {
                        $rawNetworkField = trim($matches[1]); 
                    }
                    $network = $rawNetworkField !== '' ? strtoupper($rawNetworkField) : $currency;

                    // 读取内层的链级真实状态
                    $canWd  = $ch['withdrawEnabled'] ?? null;
                    $canDep = $ch['depositEnabled'] ?? null;

                    if ($canWd === null || $canDep === null) {
                        $skipped++;
                        continue;
                    }

                    $isWithdraw = $this->to01($canWd);
                    $isDeposit  = $this->to01($canDep);
                    
                    // 获取手续费与确认数
                    $withdrawFee = isset($ch['withdrawFeeAmount']) ? (string)$ch['withdrawFeeAmount'] : '0';
                    $confirmNum = isset($ch['depositConfirmations']) ? (int)$ch['depositConfirmations'] : 0;

                    // 重新把存活的链写回数据库，状态由 0 激活为 1
                    $ok = PlatformWithdraw::updateRecord(
                        $currency, 
                        $platform, 
                        $network, 
                        $isWithdraw, 
                        $isDeposit,
                        $withdrawFee,
                        0, // 默认精度
                        $confirmNum
                    );

                    if ($ok) $updated++; else $skipped++;
                }
            }
        }

        return ['updated' => $updated, 'skipped' => $skipped];
    }

    private function to01($v): int
    {
        if (is_bool($v)) return $v ? 1 : 0;
        if (is_numeric($v)) return ((int)$v) === 1 ? 1 : 0;
        $s = strtolower(trim((string)$v));
        return in_array($s, ['1', 'true', 'yes', 'y', 'on'], true) ? 1 : 0;
    }
}