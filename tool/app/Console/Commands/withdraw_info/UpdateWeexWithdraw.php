<?php

namespace App\Console\Commands\withdraw_info;

use App\Model\CurrencyQuotation;
use App\Model\PlatformWithdraw;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Console\Command;

class UpdateWeexWithdraw extends Command
{
    protected $signature = 'update_weex_withdraw {--try=3}';
    protected $description = 'Weex: Get Coins -> 更新 platform_withdraw（单币毫秒拉闸 + 彻底无视外层假数据版）';

    // Weex Public API
    private const BASE_URL = 'https://api-spot.weex.com';
    private const PATH = '/api/v3/coins';

    public function handle()
    {
        // 确保你的 CurrencyQuotation 模型里定义了 PLATFORM_WEEX
        $platform = CurrencyQuotation::PLATFORM_WEEX; 

        $tryMax  = max(1, (int)$this->option('try'));

        $client = new Client([
            'base_uri' => self::BASE_URL,
            'timeout' => 30,
            'connect_timeout' => 10,
            'http_errors' => false,
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => 'WeexApiClient/1.0',
            ],
        ]);

        $this->info("Fetching Weex coins list...");
        $resp = $this->requestWeex($client, $tryMax);

        if (!$resp['ok']) {
            $this->error("Weex 请求失败: " . $resp['err']);
            return 1;
        }

        $data = $resp['json'];
        if (!is_array($data)) {
            $this->error("Weex 返回数据不是数组");
            return 2;
        }

        $ret = $this->updateFromData($data, $platform);
        
        $this->info("Weex withdraw info done. updated={$ret['updated']}, skipped={$ret['skipped']}");
        return 0;
    }

    private function requestWeex(Client $client, int $tryMax): array
    {
        $lastErr = '';

        for ($i = 1; $i <= $tryMax; $i++) {
            try {
                $resp = $client->request('GET', self::PATH);

                $code = (int)$resp->getStatusCode();
                $raw  = (string)$resp->getBody();

                if ($code < 200 || $code >= 300) {
                    $lastErr = "HTTP {$code}: " . mb_substr($raw, 0, 500);
                    sleep($i);
                    continue;
                }

                $json = json_decode($raw, true);
                if (json_last_error() !== JSON_ERROR_NONE || !is_array($json)) {
                    $lastErr = 'JSON parse error: ' . json_last_error_msg();
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

        foreach ($data as $coinItem) {
            if (!is_array($coinItem)) {
                $skipped++;
                continue;
            }

            // 获取主币种名称
            $currency = isset($coinItem['coin']) ? strtoupper(trim((string)$coinItem['coin'])) : '';
            if (!$currency) {
                $skipped++;
                continue;
            }

            // ==========================================
            // 🚀 核心防线 1：单币种毫秒级物理拉闸
            // 精准匹配表结构：platform 和 currency_name
            // 无论接口怎么返回，先把你所有的历史网络记录清零，专治偷偷下架的死链！
            // ==========================================
            PlatformWithdraw::where('platform', $platform)
                ->where('currency_name', $currency)
                ->update([
                    'is_withdraw' => 0,
                    'is_deposit'  => 0
                ]);

            $networkList = $coinItem['networkList'] ?? [];

            // ==========================================
            // 🚀 核心防线 2：无链即判死刑，彻底无视外层假数据
            // ==========================================
            if (empty($networkList)) {
                
                // 强行硬编码写死，看都不看 withdrawAllEnable 一眼
                $isWithdraw = 0;
                $isDeposit  = 0;

                // 依靠你严谨的 updateRecord 方法，将 0 安全写入数据库
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
                foreach ($networkList as $netItem) {
                    if (!is_array($netItem)) {
                        $skipped++;
                        continue;
                    }

                    // 提取网络名称并正则清洗括号
                    $rawNetworkField = isset($netItem['network']) ? trim((string)$netItem['network']) : '';
                    if (preg_match('/\((.*?)\)/', $rawNetworkField, $matches)) {
                        $rawNetworkField = trim($matches[1]); 
                    }
                    $network = $rawNetworkField !== '' ? strtoupper($rawNetworkField) : $currency;

                    // 读取内层的链级真实状态
                    $canWd  = $netItem['withdrawEnable'] ?? null;
                    $canDep = $netItem['depositEnable'] ?? null;
                    
                    if ($canWd === null || $canDep === null) {
                        $skipped++;
                        continue;
                    }

                    $isWithdraw = $this->to01($canWd);
                    $isDeposit  = $this->to01($canDep);
                    $withdrawFee = isset($netItem['withdrawFee']) ? (string)$netItem['withdrawFee'] : '0';
                    $confirmNum = isset($netItem['minConfirm']) ? (int)$netItem['minConfirm'] : 0;

                    // 重新把存活的链写回数据库，状态由 0 激活为 1
                    $ok = PlatformWithdraw::updateRecord(
                        $currency, 
                        $platform, 
                        $network, 
                        $isWithdraw, 
                        $isDeposit,
                        $withdrawFee,
                        0, // 精度默认0
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