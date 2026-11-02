<?php

namespace App\Modules\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Services\MoyasarWebhooks;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Moyasar's payment events. Answered at once; the work is queued.
 */
final class MoyasarWebhookController extends Controller
{
    public function __invoke(Request $request, MoyasarWebhooks $webhooks): Response
    {
        $payload = json_decode($request->getContent(), true);

        if (! is_array($payload) || array_is_list($payload)) {
            return response()->noContent(401);
        }

        return response()->noContent($webhooks->receive($payload, $request->getContent()) ? 200 : 401);
    }
}
