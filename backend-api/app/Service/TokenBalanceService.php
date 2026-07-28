<?php

namespace App\Service;

/**
 * 区块链代币余额查询服务 (兼容 PHP 7.2)
 * 依赖扩展: curl, bcmath
 */
class TokenBalanceService
{
    // ==========================================
    // 🚀 请在这里填入你申请的 Infura API Key (Project ID)
    // ==========================================

    private $rpcUrls = [];

    public function __construct()
    {
        // 动态初始化 RPC 节点路由
        $this->rpcUrls = [
            // ETH 走你的专属 Infura 节点，极其稳定
            'ETH' => config('services.rpc.eth'), 
            
            // 注：Infura 官方不支持 BSC，这里为你保留最稳的币安官方全节点
            'BSC' => config('services.rpc.bsc'),
            
            // 注：Infura 官方不支持 SOL，这里为你保留 Solana 官方主网节点
            'SOL' => config('services.rpc.sol')
        ];
    }

    /**
     * 查询 ETH 链余额
     * @param string $walletAddress 钱包地址
     * @param string|null $contractAddress 合约地址 (传 null 则查询 ETH 主币余额)
     * @param int $decimals 代币精度 (ETH主币和大多数ERC20是18，USDT通常是6)
     * @return string 余额
     */
    public function getEthBalance($walletAddress, $contractAddress = null, $decimals = 18)
    {
        return $this->getEvmBalance('ETH', $walletAddress, $contractAddress, $decimals);
    }

    /**
     * 查询 BSC 链余额
     * @param string $walletAddress 钱包地址
     * @param string|null $contractAddress 合约地址 (传 null 则查询 BNB 主币余额)
     * @param int $decimals 代币精度 (BNB主币和大多数BEP20是18，USDT通常是18)
     * @return string 余额
     */
    public function getBscBalance($walletAddress, $contractAddress = null, $decimals = 18)
    {
        return $this->getEvmBalance('BSC', $walletAddress, $contractAddress, $decimals);
    }

    /**
     * 查询 SOL 链余额
     * @throws \Exception 请求或解析失败时抛出异常
     */
    public function getSolBalance($walletAddress, $tokenMintAddress = null)
    {
        $rpcUrl = $this->rpcUrls['SOL'];

        if (empty($tokenMintAddress)) {
            // 查询 SOL 主币余额
            $payload = [
                'jsonrpc' => '2.0',
                'id'      => 1,
                'method'  => 'getBalance',
                'params'  => [$walletAddress]
            ];

            $response = $this->sendRpcRequest($rpcUrl, $payload);
            
            // 必须明确存在 result 且有 value，才算查询成功
            if (isset($response['result']['value'])) {
                return bcdiv((string)$response['result']['value'], bcpow('10', '9'), 9);
            }
            
            throw new \Exception("SOL 主币余额解析失败: 节点未返回预期的 value 字段");
            
        } else {
            // 查询 SPL 代币余额
            $payload = [
                'jsonrpc' => '2.0',
                'id'      => 1,
                'method'  => 'getTokenAccountsByOwner',
                'params'  => [
                    $walletAddress,
                    ['mint' => $tokenMintAddress],
                    ['encoding' => 'jsonParsed']
                ]
            ];

            $response = $this->sendRpcRequest($rpcUrl, $payload);
            
            if (!isset($response['result']['value'])) {
                throw new \Exception("SOL 代币余额解析失败: 节点未返回预期的 value 字段");
            }

            // Solana 如果 value 是空数组，说明该钱包从未接收过该代币，这是唯一可以合法返回 '0' 的场景
            if (empty($response['result']['value'])) {
                return '0';
            }

            $totalBalance = '0';
            
            foreach ($response['result']['value'] as $accountData) {
                if (isset($accountData['account']['data']['parsed']['info']['tokenAmount']['uiAmountString'])) {
                    $amount = $accountData['account']['data']['parsed']['info']['tokenAmount']['uiAmountString'];
                    $totalBalance = bcadd($totalBalance, $amount, 9);
                }
            }
            
            // 去除多余的 0
            if (strpos($totalBalance, '.') !== false) {
                $totalBalance = rtrim(rtrim($totalBalance, '0'), '.');
            }
            
            // 如果加起来是空，说明解析数据结构不对，不能默认返回 0，应当抛错
            if ($totalBalance === '') {
                 throw new \Exception("SOL 代币余额累加异常: 数据结构不符");
            }
            
            return $totalBalance;
        }
    }

    /**
     * 处理 EVM 兼容链 (ETH/BSC) 的核心逻辑
     * @throws \Exception
     */
    private function getEvmBalance($chain, $walletAddress, $contractAddress, $decimals)
    {
        $rpcUrl = $this->rpcUrls[$chain];

        if (empty($contractAddress)) {
            $payload = [
                'jsonrpc' => '2.0',
                'id'      => 1,
                'method'  => 'eth_getBalance',
                'params'  => [$walletAddress, 'latest']
            ];
        } else {
            $cleanAddress = str_replace('0x', '', strtolower($walletAddress));
            $paddedAddress = str_pad($cleanAddress, 64, '0', STR_PAD_LEFT);
            $data = '0x70a08231' . $paddedAddress;

            $payload = [
                'jsonrpc' => '2.0',
                'id'      => 1,
                'method'  => 'eth_call',
                'params'  => [
                    [
                        'to'   => $contractAddress,
                        'data' => $data
                    ],
                    'latest'
                ]
            ];
        }

        $response = $this->sendRpcRequest($rpcUrl, $payload);

        if (!isset($response['result'])) {
             throw new \Exception("EVM 节点响应异常: 缺少 result 字段");
        }

        // 【安全核心】0x 意味着调用执行了，但没有返回任何数据。
        // 这通常是因为合约地址填错了，或者填的根本不是一个代币合约！绝不能把它当成余额 0！
        if ($response['result'] === '0x') {
            throw new \Exception("链上查询异常: 合约未返回数据(0x)，请检查合约地址是否正确或是否为标准代币。");
        }

        // 真正的余额 0，节点会返回 '0x0' (主币) 或 '0x00000000...' (代币)
        $rawBalance = $this->hexToDec($response['result']);
        return bcdiv($rawBalance, bcpow('10', (string)$decimals), $decimals);
    }

    /**
     * 发送 HTTP cURL 请求并统一处理所有底层报错
     * @throws \Exception
     */
    private function sendRpcRequest($url, $payload)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5); 
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);       
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0 Safari/537.36');
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); 
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        $result = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // 1. 网络层报错 (超时、连不上)
        if ($error) {
            throw new \Exception("RPC 网络请求失败: " . $error);
        }

        // 2. HTTP 状态码报错 (如被拦截返回 403, 500 等)
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new \Exception("RPC 节点拒绝访问 (HTTP 状态码: {$httpCode}): " . mb_substr($result, 0, 200));
        }

        $decoded = json_decode($result, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("RPC 节点返回的不是合法 JSON: " . mb_substr($result, 0, 200));
        }

        // 3. RPC 协议层业务报错 (如 API Key 额度用光、参数错误)
        if (isset($decoded['error'])) {
            $errMsg = isset($decoded['error']['message']) ? $decoded['error']['message'] : json_encode($decoded['error']);
            throw new \Exception("RPC 业务报错: " . $errMsg);
        }

        return $decoded;
    }

    /**
     * 16 进制转 10 进制
     */
    private function hexToDec($hex)
    {
        $hex = strtolower(str_replace('0x', '', $hex));
        if ($hex === '') {
             // 这是为了安全兜底，正常逻辑不会走到这里，如果走到抛出异常最好
             throw new \Exception("hexToDec 解析异常: 接收到了空字符串");
        }
        
        $dec = '0';
        $len = strlen($hex);
        for ($i = 1; $i <= $len; $i++) {
            $hexDigit = hexdec($hex[$i - 1]);
            $power = bcpow('16', (string)($len - $i));
            $term = bcmul((string)$hexDigit, $power);
            $dec = bcadd($dec, $term);
        }
        return $dec;
    }
}