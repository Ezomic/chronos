<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\CreateEventTemplateAction;
use App\Concerns\InteractsWithCurrentUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreEventTemplateRequest;
use App\Http\Requests\Settings\UpdateEventTemplateRequest;
use App\Models\EventTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class EventTemplateController extends Controller
{
    use InteractsWithCurrentUser;

    public function edit(): Response
    {
        $user = $this->currentUser();

        $templates = $user->eventTemplates()
            ->orderBy('name')
            ->get()
            ->map(fn (EventTemplate $template) => [
                'id' => $template->id,
                'name' => $template->name,
                'calendar_id' => $template->calendar_id,
                'title' => $template->title,
                'description' => $template->description,
                'location' => $template->location,
                'all_day' => $template->all_day,
                'duration_minutes' => $template->duration_minutes,
                'default_start_time' => $template->default_start_time,
                'frequency' => $template->frequency,
                'reminder_minutes' => $template->reminder_minutes,
            ])
            ->values();

        $calendars = $user->calendars()
            ->where('is_writable', true)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get(['id', 'name', 'color', 'is_default'])
            ->values();

        return Inertia::render('settings/EventTemplates', [
            'templates' => $templates,
            'calendars' => $calendars,
        ]);
    }

    public function store(StoreEventTemplateRequest $request, CreateEventTemplateAction $action): RedirectResponse
    {
        $action->handle($this->currentUser(), $this->normalized($request));

        return back()->with('status', 'Template saved.');
    }

    public function update(UpdateEventTemplateRequest $request, EventTemplate $eventTemplate): RedirectResponse
    {
        Gate::authorize('update', $eventTemplate);

        $eventTemplate->update($this->normalized($request));

        return back()->with('status', 'Template updated.');
    }

    public function destroy(EventTemplate $eventTemplate): RedirectResponse
    {
        Gate::authorize('delete', $eventTemplate);

        $eventTemplate->delete();

        return back()->with('status', 'Template deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function normalized(StoreEventTemplateRequest|UpdateEventTemplateRequest $request): array
    {
        return [
            ...$request->validated(),
            'all_day' => $request->boolean('all_day'),
        ];
    }
}
