<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\SpotListingProjectionUnavailableException;
use App\Http\Controllers\Controller;
use App\Services\SpotListingDiscoveryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SpotListingController extends Controller
{
    private $listings;

    public function __construct(SpotListingDiscoveryService $listings)
    {
        $this->listings = $listings;
    }

    public function index(Request $request)
    {
        $unknown = $this->unknownKeys($request->query(), [
            'platform_id',
            'symbol',
            'exchange_status',
            'page',
            'page_size',
        ]);
        if ($unknown !== []) {
            return errorReturn('不支持的查询参数：'.implode(', ', $unknown), 422, 422);
        }

        $validator = Validator::make($request->query(), [
            'platform_id' => ['nullable', 'integer', Rule::in([2, 3, 4, 5, 8])],
            'symbol' => ['nullable', 'string', 'max:64'],
            'exchange_status' => [
                'nullable',
                Rule::in(['unknown', 'pre_open', 'trading', 'disabled']),
            ],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', Rule::in([10, 20, 50, 100])],
        ]);
        if ($validator->fails()) {
            return errorReturn($validator->errors()->first(), 422, 422);
        }

        try {
            return successReturn($this->listings->paginate($request->query()));
        } catch (SpotListingProjectionUnavailableException $exception) {
            return errorReturn('新币雷达数据暂不可用', 50301, 503);
        }
    }

    public function operations(Request $request)
    {
        $unknown = $this->unknownKeys($request->query(), [
            'platform_id',
            'limit',
            'past_hours',
            'future_hours',
        ]);
        if ($unknown !== []) {
            return errorReturn('不支持的查询参数：'.implode(', ', $unknown), 422, 422);
        }

        $validator = Validator::make($request->query(), [
            'platform_id' => ['nullable', 'integer', Rule::in([2, 3, 4, 5, 8])],
            'limit' => ['nullable', 'integer', Rule::in([10, 20, 50, 100, 200])],
            'past_hours' => ['nullable', 'integer', Rule::in([24, 72, 168])],
            'future_hours' => ['nullable', 'integer', Rule::in([24, 72, 168])],
        ]);
        if ($validator->fails()) {
            return errorReturn($validator->errors()->first(), 422, 422);
        }

        try {
            return successReturn($this->listings->operations($request->query()));
        } catch (SpotListingProjectionUnavailableException $exception) {
            return errorReturn('新币雷达数据暂不可用', 50301, 503);
        }
    }

    public function announcements(Request $request)
    {
        $unknown = $this->unknownKeys($request->query(), [
            'platform_id',
            'symbol',
            'announcement_kind',
            'page',
            'page_size',
        ]);
        if ($unknown !== []) {
            return errorReturn('不支持的查询参数：'.implode(', ', $unknown), 422, 422);
        }

        $validator = Validator::make($request->query(), [
            'platform_id' => ['nullable', 'integer', Rule::in([2, 3, 4, 5, 8])],
            'symbol' => ['nullable', 'string', 'max:64'],
            'announcement_kind' => [
                'nullable',
                Rule::in(['spot_usdt_explicit', 'listing_candidate', 'ambiguous']),
            ],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', Rule::in([10, 20, 50, 100])],
        ]);
        if ($validator->fails()) {
            return errorReturn($validator->errors()->first(), 422, 422);
        }

        try {
            return successReturn(
                $this->listings->paginateAnnouncements($request->query())
            );
        } catch (SpotListingProjectionUnavailableException $exception) {
            return errorReturn('新币雷达数据暂不可用', 50301, 503);
        }
    }

    public function showAnnouncement(Request $request, $id)
    {
        $unknown = $this->unknownKeys($request->query(), []);
        if ($unknown !== []) {
            return errorReturn('不支持的查询参数：'.implode(', ', $unknown), 422, 422);
        }

        $announcementId = $this->positiveInteger($id);
        if ($announcementId === null) {
            return errorReturn('公告ID参数无效', 422, 422);
        }
        try {
            $result = $this->listings->announcementDetail($announcementId);
        } catch (SpotListingProjectionUnavailableException $exception) {
            return errorReturn('新币雷达数据暂不可用', 50301, 503);
        }
        if ($result === null) {
            return errorReturn('找不到该官方公告', 404, 404);
        }

        return successReturn($result);
    }

    public function show(Request $request, $id)
    {
        $unknown = $this->unknownKeys($request->query(), []);
        if ($unknown !== []) {
            return errorReturn('不支持的查询参数：'.implode(', ', $unknown), 422, 422);
        }

        $instrumentId = $this->positiveInteger($id);
        if ($instrumentId === null) {
            return errorReturn('交易对ID参数无效', 422, 422);
        }
        try {
            $result = $this->listings->detail($instrumentId);
        } catch (SpotListingProjectionUnavailableException $exception) {
            return errorReturn('新币雷达数据暂不可用', 50301, 503);
        }
        if ($result === null) {
            return errorReturn('找不到该交易对发现记录', 404, 404);
        }

        return successReturn($result);
    }

    private function unknownKeys(array $input, array $allowed): array
    {
        return array_values(array_diff(array_keys($input), $allowed));
    }

    private function positiveInteger($value)
    {
        $value = (string) $value;
        if (preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            return null;
        }
        $parsed = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        return $parsed === false ? null : (int) $parsed;
    }
}
