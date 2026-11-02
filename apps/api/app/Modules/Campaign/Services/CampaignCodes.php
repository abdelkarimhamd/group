<?php

namespace App\Modules\Campaign\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Human campaign references: GB-<year><month in Riyadh>-<number>, such as
 * GB-2611-0007. The number comes from a database sequence, so it never
 * repeats, even across months.
 */
final class CampaignCodes
{
    public function next(): string
    {
        $number = (int) DB::selectOne("SELECT nextval('campaign_code_seq') AS n")->n;
        $month = CarbonImmutable::now((string) config('groupbuy.display_timezone'))->format('ym');

        return sprintf('GB-%s-%04d', $month, $number);
    }
}
