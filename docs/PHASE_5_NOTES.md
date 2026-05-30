# Phase 5 Notes

**Date:** May 2026
**Ship criterion:** "I can run a job search end to end inside the app — track applications through a pipeline, manage the people I'm talking to, send outreach from reusable templates, and keep my calendar in sync — without leaving for a spreadsheet, CRM, or separate calendar."
**Status:** Met. The authenticated nav now carries Pipeline, Contacts, Follow-ups, Templates, and Calendar. Pipeline tracks roles across a status board with per-stage notes and offer tracking + comparison; Contacts is a lightweight CRM with outreach logging and a follow-up queue; Templates feed Mustache-style variable substitution into composed outreach; the Calendar has CRUD plus a public, token-keyed iCal subscription feed and a per-minute reminder sweep.

---

## What shipped

- **Pipeline board.** `GET /pipeline` (PipelineIndexController) renders the status board; `POST /pipeline/status` (UpdateStatusController) moves a role between `PipelineStatus` cases (`researching`, `applied`, `screen`, `onsite`, `offer`, `rejected`, `withdrawn`) and emits a `role_status_changed` Event (already present in `EventType`).
- **Pipeline add / detach.** `POST /pipeline/roles/{role}/add` (AddRoleController) and `DELETE /pipeline/roles/{role}/remove` (RemoveRoleController) attach/detach a role to/from the board. Route params are integer-constrained (`whereNumber`), resolved in the controllers — no route-model binding.
- **Application detail.** `GET /pipeline/roles/{role}` (ShowRoleController) is the per-role detail page; `PUT /pipeline/roles/{role}/application` (UpdateApplicationController) edits the application record.
- **Stage notes.** `POST /pipeline/roles/{role}/stage-notes` (StageNotes\StoreController) appends a `RoleStageNote` row scoped to the role's current stage.
- **Offer tracking + comparison.** `POST /pipeline/roles/{role}/offer` (Offers\UpsertController) records or revises an `Offer` (revisions captured as append-only `OfferRevision` rows) and emits an `offer_recorded` Event (already present in `EventType`). `GET /pipeline/offers/comparison` (Offers\ComparisonController) renders the side-by-side comparison. The comparison route is registered before the `{role}` show route so `offers/comparison` is not swallowed by the numeric role matcher.
- **Contacts CRM.** Full CRUD: `GET /contacts` (IndexController, with `ContactListFilters`), `POST /contacts` (StoreController), `GET /contacts/{contact}` (ShowController), `PUT /contacts/{contact}` (UpdateController), `DELETE /contacts/{contact}` (DestroyController). A `Contact` optionally belongs to a `Company` and carries a `last_touch` timestamp.
- **Templates CRUD.** `GET /templates` (IndexController), `POST /templates` (StoreController), `PUT /templates/{template}` (UpdateController), `DELETE /templates/{template}` (DestroyController). `Template` rows hold a reusable subject/body with `{{variable}}` placeholders.
- **Compose from template.** `POST /contacts/{contact}/compose` (ComposeController) renders a template against a contact, substituting Mustache-style `{{var}}` placeholders (hand-rolled, no Mustache dependency).
- **Outreach log.** `POST /contacts/{contact}/outreach` (Outreach\StoreController) appends an `OutreachLogEntry` (append-only — `UPDATED_AT` is null) across an `OutreachChannel` (`email`, `linkedin`, `phone`, `other`) and advances `contacts.last_touch` in one transaction.
- **Follow-up queue.** `GET /follow-ups` (IndexController, backed by `DueFollowUpsQuery`) lists contacts whose `last_touch` is null or older than `config('pipeline.follow_up_threshold_days', 7)` AND whose company has at least one role in an actively-waiting stage (`applied` or `screen`). `POST /follow-ups/{contact}/touch` (QuickTouchController) clears the flag in one click by writing a minimal `OutreachLogEntry` and stamping `last_touch`, mirroring the full outreach writer.
- **Calendar CRUD.** `GET /calendar` (IndexController), `POST /calendar` (StoreController), `PUT /calendar/{calendarEvent}` (UpdateController), `DELETE /calendar/{calendarEvent}` (DestroyController). `CalendarEvent` rows carry a `reminder_minutes` lead-time (default from `config('pipeline.default_reminder_minutes', 30)`).
- **iCal feed (public).** `GET /calendar/{token}/feed.ics` (FeedController) is registered **outside** the auth middleware group. It resolves the owning user by `calendar_feed_token` (via `User::withoutTenantScope()`), sets tenant context for the request, and returns a `text/calendar` VCALENDAR of future events built by `IcalFeedBuilder` (hand-rolled RFC 5545, no library). The body is cached per token for `config('pipeline.ical_cache_ttl', 300)` seconds; write controllers forget the same `CalendarFeedCacheKey`. Settings UI at `GET /settings/calendar` + `POST /settings/calendar/regenerate` (Settings\CalendarTokenController) shows the subscription URL and rotates the token.
- **Reminder sweep.** `calendar:send-reminders` (SendCalendarRemindersCommand) queues `CalendarEventReminderMail` for events whose reminder lead-time has elapsed and stamps them sent. Omitting `--tenant` sweeps every tenant. Scheduled `everyMinute()->withoutOverlapping()` in `routes/console.php`. The Mailable is `ShouldQueue`; the actual send goes through the `MAIL_MAILER`-configured transport (no hardcoded provider SDK).

## Integration / wiring done in this step

- **`routes/web.php`:** added use-imports and route definitions for all 13 slices. Authenticated feature routes sit inside the existing `middleware(['auth'])` group; settings calendar routes inside the existing `settings.` prefix/name group; the iCal feed route is the lone unauthenticated addition (after `home`). Mutating routes with id params use `whereNumber`.
- **`resources/js/Layouts/AuthenticatedLayout.tsx`:** replaced the disabled `Pipeline` placeholder with a live link and added `Contacts`, `Follow-ups`, `Templates`, `Calendar` to `NAV_ITEMS`.
- **`resources/js/Layouts/SettingsLayout.tsx`:** added the `Calendar feed` nav item (`/settings/calendar`).
- **`routes/console.php`:** added the `calendar:send-reminders` per-minute schedule entry.
- **`EventType`:** no changes — `ROLE_STATUS_CHANGED` and `OFFER_RECORDED` were already present from the foundation.

## Deviations / decisions worth carrying forward

- **iCal and RSS/variable substitution are hand-rolled.** `IcalFeedBuilder` emits RFC 5545 from stdlib; the compose feature does `{{var}}` substitution without Mustache. No new Composer or pnpm dependencies were added in Phase 5.
- **Email stays provider-agnostic.** `CalendarEventReminderMail` is a queued Markdown Mailable; the transport is chosen by `MAIL_MAILER` config, not a hardcoded Postmark/Resend SDK.
- **The iCal feed is the only unauthenticated route.** It is token-gated and tenant-scoped at request time. Rotating the token (settings) immediately invalidates the old URL and its cached body. Keep this route out of the auth group.
- **`whereNumber` constraints + ordering matter on `/pipeline`.** `pipeline/offers/comparison` is registered ahead of `pipeline/roles/{role}` so the literal path wins over the numeric matcher. Any future literal sub-routes under `/pipeline` must keep this ordering discipline.
- **The follow-up queue definition is owned by `DueFollowUpsQuery`.** Active stages (`applied`, `screen`) are defined locally there and mirrored on the role-card flag. Changing the "actively waiting" set means editing that one query.

## Fixes applied during verification

- **`app/FollowUps/DueFollowUpsQuery.php`:** the eager-load constraint closure for the `company` BelongsTo was type-hinted `Builder`, but `with()` passes the relation instance — changed to `BelongsTo` (Laravel passes `Illuminate\Database\Eloquent\Relations\BelongsTo` for a `belongsTo` eager-load constraint). This was a real `TypeError` in 6 FollowUps tests.
- **`tests/Feature/FollowUps/QuickTouchTest.php`:** one assertion called `OutreachLogEntry::query()->withoutTenantScope()`. `withoutTenantScope()` is a static helper on `BelongsToTenant` returning a fresh `Builder`; it is not a Builder method. Corrected to `OutreachLogEntry::withoutTenantScope()`. Test intent (count rows across all tenants) is unchanged.

## Verification results

- `php artisan migrate --force`: nothing to migrate (schema landed in the foundation commit).
- `pint --dirty`: passed.
- `php artisan test --compact`: **927 passed (3666 assertions)**, 0 failures.
- `pnpm run build`: succeeded.
