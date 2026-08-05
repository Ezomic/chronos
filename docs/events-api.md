# Chronos events API

The contract other apps write against. Chronos is the hub: zero and tempo create
events here, and this is the only supported way in.

Anything in this document is pinned by `tests/Feature/Api/ContractTest.php`. If a
change breaks a consumer, that suite fails first.

## Base URL and versioning

```
https://chronos.thijssensoftware.nl/api/v1
```

The unversioned `/api/...` paths are an alias of `v1` and still work, because the
currently deployed consumers use them. New consumers should use `/api/v1`.

A breaking change gets a new prefix rather than a mutation of this one. Adding a
field to a response is not breaking and will happen without a version bump.

## Authentication

A Sanctum bearer token, minted on the Chronos host:

```bash
php artisan calendar:token you@example.com --name=zero --ability=events:create --ability=events:manage --app=zero
```

```
Authorization: Bearer {token}
Accept: application/json
```

The token is bound to one Chronos user; events land on that user's calendars.

### Abilities

| Ability | Grants |
|---------|--------|
| `events:create` | `POST /events` |
| `events:manage` | `GET /events`, `PATCH /events/{id}`, `DELETE /events/{id}` |
| `app:{slug}` | Says which consumer the token speaks for |

`app:{slug}` is not a permission, it is an identity. The manage endpoints need it
because they scope every read and write to events that app created. A token
carrying it also cannot create an event claiming another app's source.

Tokens minted before app scoping carry `events:create` alone. They keep working,
but they cannot use the manage endpoints.

Rate limit: 60 requests per minute per token user, shared across all endpoints.

## Consumer apps

`source.app` must be one of the apps in `config/chronos.php`, set from the
`CHRONOS_CONSUMER_APPS` environment variable as a comma-separated list of
`slug:Label` pairs:

```
CHRONOS_CONSUMER_APPS="zero:Open in Mail,tracker:Open in Tracker,tempo:Open in Tempo"
```

The label is what the calendar shows on the link back to the source row. Adding a
consumer is an environment change on the Chronos host, not a code change.

The allow-list exists so a stored `source_url` can never be rendered as a link
for an app Chronos does not know.

## POST /events

Create an event.

```json
{
  "title": "Kickoff with Acme",
  "description": null,
  "location": null,
  "starts_at": "2026-07-20T09:00:00+02:00",
  "ends_at": "2026-07-20T09:30:00+02:00",
  "all_day": false,
  "timezone": "Europe/Amsterdam",
  "calendar": "Work",
  "source": {
    "app": "zero",
    "type": "email",
    "id": "01JZ8XABCDEF0123456789ABCD",
    "url": "https://zero.thijssensoftware.nl/emails/ref/01JZ8XABCDEF0123456789ABCD"
  }
}
```

| Field | Required | Notes |
|-------|----------|-------|
| `title` | yes | max 255 |
| `starts_at` | yes | any parseable date; send an offset or set `timezone` |
| `ends_at` | yes | after `starts_at`, or equal when `all_day` |
| `all_day` | no | default false |
| `timezone` | no | IANA name; defaults to the user's Settings timezone |
| `description` | no | |
| `location` | no | max 255 |
| `calendar` | no | one of the user's writable calendars, by id or by name |
| `source` | no | all four keys required together |

**Idempotency.** A request whose `source` triple (`app`, `type`, `id`) matches an
event Chronos already holds returns that event with **200** instead of creating a
second one with **201**. Retry freely. Requests with no `source` create every
time.

**All-day events.** Send dates. They are stored as an exclusive midnight-UTC span
(`2026-07-20` to `2026-07-21`) with timezone `UTC`, and are treated as floating
dates that never shift.

**Target calendar.** Without `calendar`, the event lands on the user's default
writable calendar; with no default flagged, on the first by name. Pass `calendar`
to route your events somewhere specific.

## GET /events

The calling app's own events. Needs `events:manage` and `app:{slug}`.

```
GET /api/v1/events?source[type]=email&source[id]=01JZ8XABCDEF0123456789ABCD
```

```json
{
  "data": [ { "...": "event" } ],
  "truncated": false
}
```

Capped at 200 events. `truncated` is true when the cap was hit; narrow with a
source filter rather than paging.

## PATCH /events/{id}

Partial update. Needs `events:manage` and `app:{slug}`. Send only what changed.

`starts_at` and `ends_at` move as a pair; sending one without the other is a 422.
Moving an event re-arms a reminder that had already fired.

## DELETE /events/{id}

Needs `events:manage` and `app:{slug}`. Returns **204**.

## Event payload

Every endpoint that returns an event returns this shape:

```json
{
  "id": 42,
  "title": "Kickoff with Acme",
  "description": null,
  "location": null,
  "starts_at": "2026-07-20T07:00:00+00:00",
  "ends_at": "2026-07-20T07:30:00+00:00",
  "all_day": false,
  "timezone": "Europe/Amsterdam",
  "calendar_id": 1,
  "source": {
    "app": "zero",
    "type": "email",
    "id": "01JZ8XABCDEF0123456789ABCD",
    "url": "https://zero.thijssensoftware.nl/emails/ref/01JZ8XABCDEF0123456789ABCD"
  },
  "url": "https://chronos.thijssensoftware.nl/calendar?view=day&date=2026-07-20"
}
```

`source` is `null` for events created in Chronos itself. `url` is a deep link to
the day the event falls on.

## Status codes

| Code | Meaning |
|------|---------|
| 200 | Updated, listed, or an idempotent create matched an existing event |
| 201 | Created |
| 204 | Deleted |
| 401 | Missing or invalid token |
| 403 | Token lacks the ability, has no `app:` scope, or claims another app's source |
| 404 | Event does not exist, or is not one this token may touch |
| 422 | Validation failed, or the user has no writable calendar |
| 429 | Rate limited |

A 404 rather than a 403 on the manage endpoints is deliberate: a token cannot use
the response to learn that an event it may not touch exists.

## What this API does not do

- It does not write to mirrored Google, Microsoft or ICS calendars. Those are
  read-only in Chronos, and events on them are never returned as manageable.
- It does not create recurring events. `POST /events` has no recurrence field;
  send individual events, or create the series in the Chronos UI.
- It does not set reminders. Reminders are a Chronos-side setting.
