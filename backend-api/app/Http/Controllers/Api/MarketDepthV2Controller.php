<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Service\RedisService;
use App\Model\CurrencyQuotation;
use App\Model\Users;
class MarketDepthV2Controller extends Controller
{
    /**
     * 🚀 V2 高性能全量检索接口 - 最终测试稳定版
     * 修复项：
     * 1. 负价差拦截：确保 D 和 D_P 必须 > 0
     * 2. 全量过滤：解决 total_price 开启后数据消失的感官 Bug
     * 3. 屏蔽兼容：修复 block_id_temp 字符串/数组传参失效问题
     * 4. 字段对齐：严格适配 Go 引擎 MiniDiffStore 字段名 (b, bq, bp_p 等)
     */
    public function deepDiffV2(Request $request)
    {
        $user_id = $request->attributes->get('user_id');
        if (!$user_id) return errorReturn('请重新登录', 50008);

        // 1. 获取标准化参数
        $page          = (int)($request->get('page') ?? 1);
        $pageSize      = (int)($request->get('page_size') ?? 50);
        $type          = $request->get('type', 'best'); 
        $symbol_query  = $request->get('symbol');
        $total_price   = (float)$request->get('total_price', 0); 
        $is_margin     = (int)$request->get('is_margin');
        
        // 🚀 明确解析前端是否传了价差参数
        $diff_price_raw = $request->get('diff_price');
        $has_diff_price = ($diff_price_raw !== null && $diff_price_raw !== '');
        $diff_price_val = (float)$diff_price_raw;

        // 🚀 新增参数：解析前端传来的 ids (兼容数组和逗号拼接的字符串)
        $req_ids_raw = $request->get('ids');
        $req_ids = [];
        if (!empty($req_ids_raw)) {
            $temp_ids = is_string($req_ids_raw) ? explode(',', $req_ids_raw) : (array)$req_ids_raw;
            $req_ids = array_filter(array_map('intval', $temp_ids));
        }
        
        $platform      = !empty($request->get('platform')) ? array_map('intval', (array)$request->get('platform')) : [];
        
        
        $user = Users::find($user_id);
        if ($user && $user->block_platform) {
    // 🚀 核心修复：用 array_map 将 explode 出来的字符串数组转为纯 int 数组
            $db_block_platforms = array_map('intval', explode(',', $user->block_platform));
            
            $platform = array_merge($platform, $db_block_platforms);
            $platform = array_unique($platform);
        }
        $quote_name    = array_filter((array)$request->get('quote_name'));
        
        // 🚀 block_symbol 改为搜索交易对功能 (转大写防呆)
        $block_symbol_raw = $request->get('block_symbol');
        $search_symbols = [];
        if (!empty($block_symbol_raw)) {
            $search_symbols = array_filter(array_map('strtoupper', (array)$block_symbol_raw));
        }

        // 屏蔽ID处理
        $block_id_raw = $request->get('block_id_temp');
        $block_ids = [];
        if (!empty($block_id_raw)) {
            $temp_ids = is_string($block_id_raw) ? explode(',', $block_id_raw) : (array)$block_id_raw;
            $block_ids = array_map('intval', $temp_ids);
        }

        $redis = RedisService::getInstance(9);
        $indexKey = ($type === 'plus') ? 'v2:list_index_plus' : 'v2:list_index_best';

        // 2. 静态配置预加载
        $enabledMatchIds = DB::table('currency_match')->where('is_enabled', 1)->pluck('id', 'id')->toArray();
        $userFilters     = DB::table('user_diff_filter')->where('user_id', $user_id)->pluck('diff_id', 'diff_id')->toArray();

        $totalCount = 0;
        $pagedIds = [];

        if (!empty($req_ids)) {
            // ==========================================
            // 🚨 场景 A：命中 ids！终极性能飞跃
            // ==========================================
            $totalCount = count($req_ids);
            $pagedIds = array_slice($req_ids, ($page - 1) * $pageSize, $pageSize);
        } else {
            // ==========================================
            // 📝 场景 B：常规全量扫描过滤 / 搜索过滤
            // ==========================================
            
            // 3. 获取数据源
            $allIndexItems = [];
            if (!empty($search_symbols)) {
                // 🚨 搜索模式：必须查全量 Hash，以防负价差被 Index 过滤掉
                $allDataRaw = $redis->hGetAll('v2:all_diff_data');
                if (!empty($allDataRaw)) {
                    foreach ($allDataRaw as $json) {
                        $item = json_decode($json, true);
                        if ($item) $allIndexItems[] = $item;
                    }
                }
            } else {
                // 常规模式：查极速 Index
                $rawIndexJson = $redis->get($indexKey);
                if (!empty($rawIndexJson) && is_string($rawIndexJson)) {
                    $allIndexItems = json_decode($rawIndexJson, true, 512, JSON_BIGINT_AS_STRING);
                }
            }

            if (!is_array($allIndexItems) || empty($allIndexItems)) return $this->formatLegacyResponse($page, $pageSize, 0, []);

            // 4. 【第一阶段：全量池初步过滤】
            $qualifiedPool = [];
            
            foreach ($allIndexItems as $item) {
                $diffId = (int)($item['i'] ?? 0);
                // 如果用户传了 is_margin=1，则必须 im 为 1 的才保留
                if ($is_margin === 1 && (int)($item['im'] ?? 0) !== 1) {
                    continue;
                }

                // 🚀 搜索匹配逻辑
                $isSearchMatch = false;
                if (!empty($search_symbols)) {
                    $cn = strtoupper($item['cn'] ?? '');
                    $qn = strtoupper($item['qn'] ?? '');
                    if (in_array($cn, $search_symbols, true) || in_array($cn . $qn, $search_symbols, true)) {
                        $isSearchMatch = true;
                    } else {
                        continue; // 搜索模式下，不匹配的直接剔除
                    }
                }

                // 🚀 核心校验 A：精准价差拦截
                $idxDiff = (float)(($type === 'plus') ? ($item['d_p'] ?? 0) : ($item['d'] ?? 0));
                
                if ($has_diff_price) {
                    // 如果传了差价，不管是不是搜索，一律严格拦截
                    if ($idxDiff < $diff_price_val) continue;
                } else {
                    // 如果没传差价：搜索模式下不拦截(列全部)，常规大盘模式拦截 <=0 的数据
                    if (!$isSearchMatch && $idxDiff <= 0) continue;
                }

                // 校验 B：屏蔽 ID 与 平台
                if (!empty($block_ids) && in_array($diffId, $block_ids, true)) continue;
                if (!empty($platform) && (in_array((int)$item['bp'], $platform, true) || in_array((int)$item['sp'], $platform, true))) continue;

                // 校验 C：金额 (HAPPY 币召回逻辑)
                if ($total_price > 0) {
                    $bP = (float)(($type === 'plus') ? ($item['bp_p'] ?? 0) : ($item['b'] ?? 0));
                    $bQ = (float)(($type === 'plus') ? ($item['bq_p'] ?? 0) : ($item['bq'] ?? 0));
                    $sP = (float)(($type === 'plus') ? ($item['sp_p'] ?? 0) : ($item['s'] ?? 0));
                    $sQ = (float)(($type === 'plus') ? ($item['sq_p'] ?? 0) : ($item['sq'] ?? 0));
                    if (($bP * $bQ) < $total_price && ($sP * $sQ) < $total_price) continue;
                }

                // 校验 D：关键词/禁用/黑名单
                if ($symbol_query && stripos(strtoupper(($item['cn'] ?? '') . ($item['qn'] ?? '')), strtoupper($symbol_query)) === false) continue;
                if (!isset($enabledMatchIds[$item['m']])) continue;
                if (isset($userFilters[$diffId])) continue;

                $qualifiedPool[] = $item;
            }

            // 5. 保持权重排序 (L优先，D降序)
            usort($qualifiedPool, function($a, $b) use ($type) {
                $lvlA = ($type === 'plus') ? ($a['l_p'] ?? 0) : ($a['l'] ?? 0);
                $lvlB = ($type === 'plus') ? ($b['l_p'] ?? 0) : ($b['l'] ?? 0);
                if ($lvlA !== $lvlB) return $lvlB <=> $lvlA;
                $diffA = (float)(($type === 'plus') ? ($a['d_p'] ?? 0) : ($a['d'] ?? 0));
                $diffB = (float)(($type === 'plus') ? ($b['d_p'] ?? 0) : ($b['d'] ?? 0));
                return $diffB <=> $diffA;
            });

            $totalCount = count($qualifiedPool);
            if ($totalCount === 0) return $this->formatLegacyResponse($page, $pageSize, 0, []);

            $pagedData  = array_slice($qualifiedPool, ($page - 1) * $pageSize, $pageSize);
            $pagedIds   = array_column($pagedData, 'i');
        }

        // 6. 执行分页与详情抓取 (共用部分，基于 hMGet)
        if (empty($pagedIds)) return $this->formatLegacyResponse($page, $pageSize, $totalCount, []);

        $rawDetails = $redis->hMGet('v2:all_diff_data', $pagedIds);
        
        $finalItems = [];
        foreach ($pagedIds as $id) {
            if (isset($rawDetails[$id]) && $rawDetails[$id]) {
                $detail = json_decode($rawDetails[$id], true, 512, JSON_BIGINT_AS_STRING);
                
                // 🚀 【第二阶段：详情层终极补杀】
                // 解决 Redis 详情更新滞后导致的负溢价数据“溜进”列表的问题
                $realTimeD = (float)(($type === 'plus') ? ($detail['d_p'] ?? 0) : ($detail['d'] ?? 0));
                $shouldFilter = false;

                if ($has_diff_price) {
                    // 传了差价，直接拦截
                    if ($realTimeD < $diff_price_val) $shouldFilter = true;
                } else {
                    // 没传差价，如果是常规扫描进来的（不是搜索也不是指定IDs），拦截 <=0
                    if (empty($search_symbols) && empty($req_ids) && $realTimeD <= 0) {
                        $shouldFilter = true;
                    }
                }

                if ($shouldFilter) {
                    $totalCount--; // 实时回扣总数，保持分页准确
                    continue;
                }

                $finalItems[] = $detail;
            }
        }

        return $this->formatLegacyResponse($page, $pageSize, $totalCount, $this->attachExtraBusinessDataV2($finalItems, $user_id));
    }

    public function deepDiffPlusV2(Request $request)
    {
        $request->merge(['type' => 'plus']);
        return $this->deepDiffV2($request);
    }

    /**
     * 🚀 业务数据挂载：对齐线上稳定版字段
     */
    private function attachExtraBusinessDataV2($items, $user_id)
    {
        if (empty($items)) return [];

        $ids = array_column($items, 'i');
        $matchConfigs = DB::table('market_depth_diff')->whereIn('id', $ids)->get()->keyBy('id');
        $collectMap   = DB::table('market_depth_collect')->where('user_id', $user_id)->whereIn('diff_id', $ids)->get()->keyBy('diff_id');
        $remarkMap    = DB::table('market_depth_remark')->where('user_id', $user_id)->whereIn('diff_id', $ids)->get()->keyBy('diff_id');

        $currencyNames = collect($items)->pluck('cn')->unique()->toArray();
        $currencyIds   = DB::table('currency')->whereIn('name', $currencyNames)->pluck('id', 'name');
        
        $pwMap = [];
        if ($currencyIds->isNotEmpty()) {
            $platIds = collect($items)->pluck('bp')->merge(collect($items)->pluck('sp'))->unique()->toArray();
            $pwRows  = DB::table('platform_withdraw')->whereIn('currency_id', $currencyIds->values()->toArray())->whereIn('platform', $platIds)->get();
            foreach ($pwRows as $r) {
                $pwMap[$r->currency_id][$r->platform][] = ['network' => $r->network, 'is_withdraw' => (int)$r->is_withdraw, 'is_deposit' => (int)$r->is_deposit];
            }
        }

        $pText = CurrencyQuotation::$platform_text;

        $f = function($num) {
            if ($num === null || $num === "") return "0";
            $str = sprintf("%.18f", (float)$num);
            $str = rtrim(rtrim($str, '0'), '.');
            return $str === "" ? "0" : $str;
        };

        $final = [];
        foreach ($items as $i) {
            $diffId = $i['i'];
            $config = $matchConfigs[$diffId] ?? null;
            if (!$config) continue;

            $cid = $currencyIds[strtoupper($i['cn'])] ?? 0;
            $bW = ($cid && isset($pwMap[$cid][$i['bp']])) ? $pwMap[$cid][$i['bp']] : [];
            $sW = ($cid && isset($pwMap[$cid][$i['sp']])) ? $pwMap[$cid][$i['sp']] : [];
            
            $sumFn = function($r) {
                $aw = 0; $ad = 0; foreach ($r as $x) { if ($x['is_withdraw']) $aw = 1; if ($x['is_deposit']) $ad = 1; }
                return ['any_withdraw' => $aw, 'any_deposit' => $ad, 'networks' => $r];
            };

            $final[] = [
                "id"            => $diffId,
                "match_id"      => $i['m'],
                "currency_name" => $i['cn'],
                "quote_name"    => $i['qn'],
                "symbol"        => $i['cn'], 
                "buy_platform"  => $i['bp'],
                "buy_price"     => $f($i['b']), 
                "buy_num"       => $f($i['bq']),
                "sell_match_id" => $i['m'],
                "sell_quote_name" => $config->sell_quote_name,
                "sell_symbol"   => strtoupper($i['cn'] . $config->sell_quote_name),
                "sell_platform" => $i['sp'],
                "sell_price"    => $f($i['s']),
                "sell_num"      => $f($i['sq']),
                "price_diff"    => number_format($i['d'], 4) . " %",
                "total_buy_price"  => $f($i['b'] * $i['bq']),
                "total_sell_price" => $f($i['s'] * $i['sq']),
                "total_deal_price" => $f(($i['b'] * $i['bq']) + ($i['s'] * $i['sq'])),
                "amount_level"     => $i['l'],
                "is_show"          => 1,
                "updated_at"       => $i['t'],
                "buy_price_plus"   => $f($i['bp_p']), 
                "sell_price_plus"  => $f($i['sp_p']),
                "buy_num_plus"     => $f($i['bq_p']),
                "sell_num_plus"    => $f($i['sq_p']),
                "total_buy_plus"   => $f($i['bp_p'] * $i['bq_p']),
                "total_sell_plus"  => $f($i['sp_p'] * $i['sq_p']),
                "amount_level_plus" => $i['l_p'],
                "price_diff_plus"  => number_format($i['d_p'], 4) . " %",
                "is_collect"       => isset($collectMap[$diffId]) ? $collectMap[$diffId]->status : 0,
                "collect_id"       => $collectMap[$diffId]->id ?? null,
                "remark"           => $remarkMap[$diffId]->remark ?? null,
                "remark_id"        => $remarkMap[$diffId]->id ?? null,
                "sell_platform_margin" => (bool)$i['im'],
                "buy_withdraw_info"    => $bW,
                "sell_withdraw_info"   => $sW,
                "buy_withdraw_summary" => $sumFn($bW),
                "sell_withdraw_summary" => $sumFn($sW),
                "platform_buy"         => $pText[$i['bp']] ?? "Other",
                "platform_sell"        => $pText[$i['sp']] ?? "Other",
                "buy_price_fmt"        => $f($i['b']),
                "sell_price_fmt"       => $f($i['s']),
                "margin_status"        => $i['im']
            ];
        }
        return $final;
    }

    private function formatLegacyResponse($p, $ps, $t, $d)
    {
        return response()->json([
            "type" => "ok", "code" => 200, "message" => "success",
            "data" => [
                "current_page" => $p, "data" => $d, 
                "from" => ($t > 0) ? ($p-1)*$ps+1 : 0, 
                "last_page" => (int)ceil($t/$ps), "per_page" => $ps, "total" => $t
            ]
        ]);
    }
}