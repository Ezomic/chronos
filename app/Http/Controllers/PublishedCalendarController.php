<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Calendar;
use App\Services\Calendar\IcsFeedWriter;
use Symfony\Component\HttpFoundation\Response;

class PublishedCalendarController extends Controller
{
    /**
     * Serve a published calendar as an ICS feed.
     *
     * Unauthenticated by necessity: a phone's calendar app subscribes with a
     * plain URL and no way to sign in. The token in that URL is the whole of
     * the access control, which is why it is long, unguessable, and revocable.
     */
    public function show(string $token, IcsFeedWriter $writer): Response
    {
        $calendar = Calendar::query()
            ->whereNotNull('publish_token')
            ->where('publish_token', $token)
            ->first();

        // A wrong token and a calendar that was never published answer
        // identically, so the response cannot be used to learn which tokens
        // exist.
        abort_if($calendar === null, 404);

        return response($writer->write($calendar), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="'.$this->filename($calendar).'"',
            // Nothing in between should hold a copy of somebody's calendar.
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    private function filename(Calendar $calendar): string
    {
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $calendar->name) ?? 'calendar';

        return trim($name, '-').'.ics';
    }
}
