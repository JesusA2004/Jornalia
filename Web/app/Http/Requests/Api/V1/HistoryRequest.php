<?php

namespace App\Http\Requests\Api\V1;

/**
 * `GET /attendance/history?from=YYYY-MM-DD&to=YYYY-MM-DD&page=1`
 */
final class HistoryRequest extends ListRequest
{
    public const MAX_RANGE_DAYS = 366;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return parent::rules() + [
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:31'],
        ];
    }
}
