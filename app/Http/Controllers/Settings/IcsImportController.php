<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\ImportIcsEventsAction;
use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ConfirmIcsImportRequest;
use App\Http\Requests\Settings\StoreIcsImportRequest;
use App\Models\Calendar;
use App\Services\Calendar\IcsFileReader;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use RuntimeException;

class IcsImportController extends Controller
{
    use InteractsWithCurrentUser;

    /** Where an uploaded file waits between preview and confirmation. */
    private const DIRECTORY = 'ics-imports';

    /**
     * Parse an uploaded file and show what it holds. Nothing is written yet:
     * the file waits under a random name until the user says go.
     */
    public function preview(StoreIcsImportRequest $request, IcsFileReader $reader): RedirectResponse
    {
        $this->forgetStaleUploads();

        $contents = $request->file('file')?->get();

        if (! is_string($contents)) {
            return $this->failed('That file could not be read.');
        }

        try {
            $events = $reader->read($contents);
        } catch (RuntimeException $e) {
            return $this->failed($e->getMessage());
        }

        $token = Str::random(40);
        Storage::disk('local')->put($this->path($token), $contents);

        Inertia::flash('icsImport', [
            'token' => $token,
            'count' => count($events),
            'events' => array_map(fn (array $event): array => [
                'title' => $event['title'],
                'starts_at' => $event['starts_at'] instanceof CarbonImmutable
                    ? $event['starts_at']->toIso8601String()
                    : null,
                'all_day' => $event['all_day'],
                'repeats' => $event['rrule'] !== null,
            ], array_slice($events, 0, 20)),
        ]);

        return back();
    }

    public function store(
        ConfirmIcsImportRequest $request,
        IcsFileReader $reader,
        ImportIcsEventsAction $action,
    ): RedirectResponse {
        $calendar = Calendar::query()
            ->where('user_id', $this->currentUser()->id)
            ->where('is_writable', true)
            ->findOr($request->integer('calendar_id'), fn () => null);

        if ($calendar === null) {
            return $this->failed('Choose one of your own calendars to import into.');
        }

        $path = $this->path($request->string('token')->toString());

        if (! Storage::disk('local')->exists($path)) {
            return $this->failed('That upload has expired. Choose the file again.');
        }

        $contents = Storage::disk('local')->get($path);

        try {
            $events = $reader->read(is_string($contents) ? $contents : '');
        } catch (RuntimeException $e) {
            return $this->failed($e->getMessage());
        }

        $result = $action->handle($calendar, $events);

        Storage::disk('local')->delete($path);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => trans_choice('{0}Nothing new to import.|[1,*]Imported :imported event(s).', $result['imported'], [
                'imported' => $result['imported'],
            ]).($result['skipped'] > 0 ? ' '.__(':skipped were already here.', ['skipped' => $result['skipped']]) : ''),
        ]);

        return back();
    }

    private function path(string $token): string
    {
        // The token is the whole filename, so a traversal attempt cannot reach
        // outside the directory: it is validated as alphanumeric first.
        return self::DIRECTORY.'/'.$token.'.ics';
    }

    /**
     * Uploads that were previewed and never confirmed. Cleared on the next
     * preview rather than by a scheduled task, which keeps this self-limiting.
     */
    private function forgetStaleUploads(): void
    {
        $disk = Storage::disk('local');
        $cutoff = now()->subDay()->getTimestamp();

        foreach ($disk->files(self::DIRECTORY) as $file) {
            if (! is_string($file)) {
                continue;
            }

            if ($disk->lastModified($file) < $cutoff) {
                $disk->delete($file);
            }
        }
    }

    private function failed(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

        return back();
    }
}
