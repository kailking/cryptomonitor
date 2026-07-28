<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Service\RedisService;
use App\Model\CurrencyQuotation;

class MarketDepthController extends Controller
{
    public function deepDiff(Request $request)
    {
        $user_id = $request->attributes->get('user_id');
        if (!$user_id) return errorReturn('请重新登录', 50008);

        // 1. 获取参数
        $page          = (int)($request->get('page') ?? 1);
        $pageSize      = (int)($request->get('page_size') ?? 50);
        $type          = $request->get('type', 'best');
        $diff_price    = $request->get('diff_price');
        $symbol        = $request->get('symbol');
        $platform      = (array)$request->get('platform');     // 黑名单平台
        $quote_name    = (array)$request->get('quote_name');   // 黑名单计价币
        $total_price   = $request->get('total_price');
        $block_symbol  = (array)$request->get('block_symbol');
        $is_margin     = $request->get('is_margin');
        $block_id_temp = (array)$request->get('block_id_temp');

        $redis = RedisService::getInstance(9);

        // 2. 环境预加载
        $enabledMatchIds = DB::table('currency_match')->where('is_enabled', 1)->pluck('id', 'id')->toArray();
        $userFilters     = DB::table('user_diff_filter')->where('user_id', $user_id)->pluck('diff_id', 'diff_id')->toArray();
        
        $marginMapAll = [];
        if ($is_margin == 1) {
            $marginRows = DB::table('platform_margin')->where('is_margin', 1)->get();
            foreach ($marginRows as $m) { $marginMapAll[$m->currency_match_id][$m->platform] = true; }
        }

        // 3. 全量内存扫描
        $allDataRaw = $redis->hGetAll('all_diff_data');
        if (empty($allDataRaw)) return $this->formatLegacyResponse($page, $pageSize, 0, []);

        $filteredItems = [];
        foreach ($allDataRaw as $json) {
            $i = json_decode($json, true);
            if (!$i) continue;

            // 🚀 重新计算 Level：其中一端超过 1000U 标记为 1 (第一梯队)
            $i['amount_level'] = ($i['total_buy_price'] >= 1000 || $i['total_sell_price'] >= 1000) ? 1 : 0;
            $i['amount_level_plus'] = ($i['total_buy_plus'] >= 1000 || $i['total_sell_plus'] >= 1000) ? 1 : 0;

            // 获取当前类型的差价
            $currentDiff = ($type === 'plus') ? $i['price_diff_plus'] : $i['price_diff'];
            
            // 🚀 核心优化：不再限制 0.05-2000，仅过滤非正数差价（负数无套利空间）
            if ($currentDiff <= 0) continue;

            // --- 基础状态过滤 ---
            if (!isset($enabledMatchIds[$i['match_id']])) continue;
            if (isset($userFilters[$i['id']])) continue;

            // 动态参数过滤
            if ($diff_price && $currentDiff <= (float)$diff_price) continue;
            if ($symbol && strtoupper($i['currency_name']) !== strtoupper($symbol)) continue;

            // 🚀 严格黑名单平台过滤逻辑
            if (!empty($platform) && (in_array($i['buy_platform'], $platform) || in_array($i['sell_platform'], $platform))) continue;
            if (!empty($quote_name) && (in_array($i['quote_name'], $quote_name) || in_array($i['sell_quote_name'], $quote_name))) continue;

            if ($total_price && ($i['total_sell_price'] <= (float)$total_price && $i['total_buy_price'] <= (float)$total_price)) continue;
            if ($block_symbol && in_array($i['currency_name'], $block_symbol)) continue;
            if ($block_id_temp && in_array($i['id'], $block_id_temp)) continue;
            if ($is_margin == 1 && !isset($marginMapAll[$i['match_id']][$i['sell_platform']])) continue;

            $filteredItems[] = $i;
        }

        // 4. 🚀 严格阶梯排序：Level (1 > 0) -> Price Diff (从大到小)
        usort($filteredItems, function ($a, $b) use ($type) {
            $lvlKey  = ($type === 'plus') ? 'amount_level_plus' : 'amount_level';
            $diffKey = ($type === 'plus') ? 'price_diff_plus' : 'price_diff';

            if ($a[$lvlKey] !== $b[$lvlKey]) {
                return $b[$lvlKey] <=> $a[$lvlKey];
            }
            return $b[$diffKey] <=> $a[$diffKey];
        });

        // 5. 分页计算
        $totalCount = count($filteredItems);
        $pagedItems = array_slice($filteredItems, ($page - 1) * $pageSize, $pageSize);

        // 6. 业务数据补全
        $finalData = $this->attachExtraBusinessData($pagedItems, $user_id);

        return $this->formatLegacyResponse($page, $pageSize, $totalCount, $finalData);
    }

    public function deepDiffPlus(Request $request)
    {
        $request->merge(['type' => 'plus']);
        return $this->deepDiff($request);
    }

    private function attachExtraBusinessData($items, $user_id)
    {
        $collect = collect($items);
        if ($collect->isEmpty()) return [];

        $ids = $collect->pluck('id')->toArray();
        $matchIds = $collect->pluck('match_id')->unique()->toArray();
        $sellPlatforms = $collect->pluck('sell_platform')->unique()->toArray();
        $currencyNames = $collect->pluck('currency_name')->map(function($n){ return strtoupper($n); })->unique()->toArray();

        $collectMap = DB::table('market_depth_collect')->where('user_id', $user_id)->whereIn('diff_id', $ids)->get()->keyBy('diff_id');
        $remarkMap  = DB::table('market_depth_remark')->where('user_id', $user_id)->whereIn('diff_id', $ids)->get()->keyBy('diff_id');
        $marginRows = DB::table('platform_margin')->whereIn('currency_match_id', $matchIds)->whereIn('platform', $sellPlatforms)->where('is_margin', 1)->get();
        $marginMap = [];
        foreach ($marginRows as $m) $marginMap[$m->currency_match_id][$m->platform] = true;

        $currencyIds = DB::table('currency')->whereIn('name', $currencyNames)->pluck('id', 'name');
        $pwMap = [];
        if ($currencyIds->isNotEmpty()) {
            $allPlat = collect($collect->pluck('buy_platform'))->merge($collect->pluck('sell_platform'))->unique()->toArray();
            $pwRows = DB::table('platform_withdraw')->whereIn('currency_id', $currencyIds->values()->toArray())->whereIn('platform', $allPlat)->get();
            foreach ($pwRows as $r) { $pwMap[$r->currency_id][$r->platform][] = ['network' => $r->network, 'is_withdraw' => (int)$r->is_withdraw, 'is_deposit' => (int)$r->is_deposit]; }
        }

        $pText = CurrencyQuotation::$platform_text;
        return $collect->map(function ($i) use ($collectMap, $remarkMap, $marginMap, $currencyIds, $pwMap, $pText) {
            $diffId = $i['id'];
            $cid = $currencyIds[strtoupper($i['currency_name'])] ?? 0;
            $bW = ($cid && isset($pwMap[$cid][$i['buy_platform']])) ? $pwMap[$cid][$i['buy_platform']] : [];
            $sW = ($cid && isset($pwMap[$cid][$i['sell_platform']])) ? $pwMap[$cid][$i['sell_platform']] : [];
            
            $sumFn = function($r) {
                $aw = 0; $ad = 0; foreach ($r as $x) { if ($x['is_withdraw']) $aw = 1; if ($x['is_deposit']) $ad = 1; }
                return ['any_withdraw' => $aw, 'any_deposit' => $ad, 'networks' => $r];
            };

            // 🚀 重点修复：科学计数法处理逻辑
            // 使用 number_format 展开小数，再用 rtrim 去除多余的 0
            $formatPrice = function($num) {
                return rtrim(rtrim(number_format($num, 14, '.', ''), '0'), '.');
            };

            return [
                "id" => $i['id'], "match_id" => $i['match_id'], "currency_name" => $i['currency_name'], "quote_name" => $i['quote_name'], "symbol" => $i['currency_name'],
                "buy_platform" => $i['buy_platform'], "buy_price" => sprintf('%.18f', $i['buy_price']), "buy_num" => sprintf('%.4f', $i['buy_num']),
                "sell_match_id" => $i['match_id'], "sell_quote_name" => $i['sell_quote_name'], "sell_symbol" => $i['currency_name'] . $i['sell_quote_name'],
                "sell_platform" => $i['sell_platform'], "sell_price" => sprintf('%.18f', $i['sell_price']), "sell_num" => sprintf('%.4f', $i['sell_num']),
                "price_diff" => number_format($i['price_diff'], 4) . " %",
                "total_buy_price" => sprintf('%.10f', $i['total_buy_price']), "total_sell_price" => sprintf('%.10f', $i['total_sell_price']), "total_deal_price" => sprintf('%.10f', $i['total_deal_price']),
                "amount_level" => $i['amount_level'], "is_show" => 1, "updated_at" => $i['updated_at'],
                "buy_price_plus" => sprintf('%.18f', $i['buy_price_plus']), "sell_price_plus" => sprintf('%.18f', $i['sell_price_plus']),
                "buy_num_plus" => sprintf('%.30f', $i['buy_num_plus']), "sell_num_plus" => sprintf('%.30f', $i['sell_num_plus']),
                "total_buy_plus" => sprintf('%.10f', $i['total_buy_plus']), "total_sell_plus" => sprintf('%.10f', $i['total_sell_plus']),
                "amount_level_plus" => $i['amount_level_plus'], "price_diff_plus" => number_format($i['price_diff_plus'], 4),
                "is_collect" => isset($collectMap[$diffId]) ? $collectMap[$diffId]->status : 0, "collect_id" => $collectMap[$diffId]->id ?? null,
                "remark" => $remarkMap[$diffId]->remark ?? null, "remark_id" => $remarkMap[$diffId]->id ?? null,
                "sell_platform_margin" => isset($marginMap[$i['match_id']][$i['sell_platform']]),
                "buy_withdraw_info" => $bW, "sell_withdraw_info" => $sW, "buy_withdraw_summary" => $sumFn($bW), "sell_withdraw_summary" => $sumFn($sW),
                "platform_buy" => $pText[$i['buy_platform']] ?? "Other", "platform_sell" => $pText[$i['sell_platform']] ?? "Other",
                "buy_price_rmb" => 0, "sell_price_rmb" => 0, 
                // 🚀 这里调用 formatPrice 解决科学计数法问题
                "buy_price_fmt" => $formatPrice($i['buy_price']), 
                "sell_price_fmt" => $formatPrice($i['sell_price']), 
                "margin_status" => (isset($marginMap[$i['match_id']][$i['sell_platform']]) ? 1 : 0)
            ];
        })->toArray();
    }

    private function formatLegacyResponse($p, $ps, $t, $d)
    {
        return response()->json(["type" => "ok", "code" => 200, "message" => "success",
            "data" => ["current_page" => $p, "data" => $d, "first_page_url" => url()->current()."?page=1", "from" => ($p-1)*$ps+1, "last_page" => ceil($t/$ps), "per_page" => $ps, "total" => $t]
        ]);
    }
}