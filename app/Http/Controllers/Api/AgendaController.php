<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\ListAgendaAction;
use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AgendaRequest;
use Illuminate\Http\JsonResponse;

class AgendaController extends Controller
{
    use InteractsWithCurrentUser;

    public function __invoke(AgendaRequest $request, ListAgendaAction $action): JsonResponse
    {
        [$from, $to] = $request->range();

        $response = response()->json([
            'data' => $action->handle($this->currentUser(), $from, $to),
            'meta' => [
                'from' => $from->utc()->format('Y-m-d\TH:i:s\Z'),
                'to' => $to->utc()->format('Y-m-d\TH:i:s\Z'),
            ],
        ]);

        // The widget polls, so let it ask "has anything changed" for a few
        // bytes. The body is per user and per range, which the hash covers.
        $response->setEtag(hash('sha256', (string) $response->getContent()));
        $response->headers->set('Cache-Control', 'private, no-cache');
        $response->isNotModified($request);

        return $response;
    }
}
