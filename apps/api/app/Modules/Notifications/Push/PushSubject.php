<?php

namespace App\Modules\Notifications\Push;

/**
 * A model a notification can be about whose screen the mobile app can open.
 * Each module implements it on its own models, so Notifications never reads
 * another module's tables.
 */
interface PushSubject
{
    /**
     * The public IDs a tap on the message uses: the app opens the order, else
     * the join, else the campaign. Leave out what does not apply.
     *
     * @return array{order_id?: string|null, participation_id?: string|null, campaign_id?: string|null}
     */
    public function pushLinks(): array;
}
