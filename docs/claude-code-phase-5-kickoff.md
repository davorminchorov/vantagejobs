# Claude Code Phase 5 Kickoff Prompt — Pipeline, Outreach, and Calendar

---

## How to use

1. You've shipped Phases 0–4 (everything up to the signals layer and graveyard).
2. Update `docs/PRD.md` if anything changed.
3. Write a short `docs/PHASE_4_NOTES.md`.
4. Start a **new** Claude Code session at the project root.
5. Paste everything between `=== PROMPT START ===` and `=== PROMPT END ===` as your first message.

This is the phase where the platform stops being purely passive and starts supporting active job-hunt workflows.

---

```
=== PROMPT START ===

# Phase 5: Pipeline, Outreach, and Calendar

You're continuing implementation of my personal job-hunting platform. Phases 0–4 are shipped. Phase 5 adds the active workflow layer: tracking applications, logging outreach, managing interview dates.

## Read this first

1. The full PRD is at `docs/PRD.md`. Special attention to:
   - Section 7.1 (Application Pipeline) — kanban, per-stage notes, interview prep, offer tracking
   - Section 7.2 (Outreach and Contacts) — logbook only, no sending
   - Section 7.7 (Calendar and Important Dates) — iCal feed export
   - Section 10, Phase 5 description and ship criterion

2. Read all prior phase notes.

3. **Reality check:** by Phase 5 you've been using the platform passively for months. You should have:
   - A short list of companies you actually want to apply to
   - A clear sense of which outreach patterns you actually use
   - Real expectations about which calendar dates you actually need to track
   
   Use that lived experience to inform what we ship. The PRD is direction; your real workflow is reality.

When done, summarize in 5–8 bullets what's already in place and what the most realistic Phase 5 priorities are for *your* use of the tool.

## How we work together

Same pattern. Interactive. PRD as direction. Boring tech. Show reasoning. Stop and confirm.

## What we're building: Phase 5

Per PRD section 10:

> Kanban pipeline view. Application detail panel with per-stage notes and interview prep checklist. Offer tracking. Contact CRM (light). Message template library. Follow-up queue. Calendar Events on Roles/Companies/Contacts. iCal feed export.
>
> **Ship criterion:** I can run an active application process end-to-end without leaving the platform, including tracking offers and upcoming interview times.

### Phase 5 scope, broken down

1. **Application Pipeline (kanban).** Per PRD section 7.1:
   - `/pipeline` page with columns: Researching, Applied, Screen, Onsite, Offer, Rejected, Withdrawn
   - Cards are Roles with company + title
   - Drag-to-change status (server action via Inertia)
   - Status changes append an Event to the Role's history
   - Default filter: status is set (i.e., the Role is part of the active pipeline)

2. **Per-card detail panel.** Per PRD section 7.1:
   - Application metadata: applied_at, source (cold/referral/recruiter), referral contact, next_action, next_action_date
   - **Per-stage notes:** separate, append-only notes per pipeline stage (screen feedback, system design feedback, onsite debrief). Timestamped. Nothing lost when status moves forward.
   - **Interview prep checklist:** lightweight markdown notes per Role for the upcoming round. Pre-populated from the company's dossier where one exists.
   - Free-form general notes alongside the per-stage notes

3. **Offer tracking.** Per PRD section 7.1:
   - When status reaches `Offer`, a structured Offer panel appears
   - Fields: base salary, equity (shares + strike + vesting), bonus, signing bonus, total comp (computed), decision_deadline, comparison vs other active offers
   - Currency-aware
   - Plain old form, no fancy negotiation simulator

4. **Follow-up indicator.** Per PRD section 7.1:
   - Cards in Applied or Screen with no activity in 7+ days get a visual flag
   - Surface on dashboard + dedicated follow-up queue

5. **Contact CRM (light).** Per PRD section 6.2.7 + 7.2:
   - Contacts table (already in Phase 0 schema if we did it right): name, role, linkedin_url, email, notes, company_id (nullable), last_touch
   - List view across all contacts with filters by company, role, last_touch range
   - Detail view per contact
   - No two-way integration. No email syncing. Logbook only.

6. **Message templates.** Per PRD section 7.2:
   - `Template` model: id, name, category (`cold_intro`, `referral_ask`, `follow_up`, `post_interview`, `decline`, `custom`), body (markdown)
   - `/templates` page: list, create, edit, delete
   - On a contact detail page, "Compose from template" pulls a template and lets me edit before copying to clipboard (no sending)

7. **Outreach log.** Per PRD section 7.2:
   - Per-contact log of "I emailed X on Y about Z"
   - Manual entry, free-form
   - Updates contact.last_touch automatically

8. **Follow-up queue.** Per PRD section 7.2:
   - `/follow-ups` page listing: contacts where last_touch was 7+ days ago AND the related Company is in Applied or Screen status
   - Sortable by oldest-pending
   - One-click "Log new touch" action

9. **Calendar Events.** Per PRD section 7.7:
   - `CalendarEvent` table: id, owner_type (Role/Company/Contact), owner_id, title, datetime (UTC), timezone, type (`interview`, `deadline`, `follow_up`, `reminder`, `custom`), notes, url
   - Reminders: email N minutes before (default 30), configurable per event
   - "Upcoming" widget on dashboard showing next 7 days

10. **iCal feed export.** Per PRD section 7.7:
    - `/calendar/{secret}/feed.ics` — private, token-protected, read-only
    - Token generated per user, regeneratable in settings
    - All future CalendarEvents in UTC with TZID for original timezone
    - Subscribe from Apple Calendar, Google Calendar, Fantastical, Thunderbird

### Phase 5 does NOT include

- Actual email sending (writing to my email account, integrating with Gmail/IMAP). Logbook only.
- Two-way calendar sync (writing back to Google Calendar). One-way iCal export only.
- LinkedIn integration, contact import from any source. Manual entry.
- Resume / CV management. Cover letter generation. Out of scope, forever.
- Auto-apply. Out of scope, forever.
- AI-anything for interview prep, message drafting, offer evaluation. Out of scope, forever.
- The daily/weekly email digest. Phase 6.

If the PRD says something Phase 5 that I haven't listed, flag it.

## How to start

Don't write code yet.

In your first response:

1. **Confirm you've read everything.** Summarize what's already in place that Phase 5 builds on.
2. **Validate the scope against your actual usage** of the platform so far. Some items in the spec may be more or less important than they sounded on paper. Push back if anything looks wrong.
3. **List the questions you need answered.** Examples:
   - Pipeline status state machine (linear or graph? can you skip stages? what triggers an automatic move?)
   - Per-stage notes model (append-only events vs editable notes per stage; how to display them in detail panel)
   - Offer tracking schema (one Offer per Role or multiple in case of revised offers; currency conversion strategy)
   - Calendar Events polymorphic association (Laravel morphTo, or three nullable foreign keys, or a separate join table)
   - iCal feed (refresh interval guidance for clients, how to handle deleted events — RFC 5545 cancellation vs simple removal)
   - Template variables ({{contact_first_name}}, {{company}}, {{role}} substitution rules)
   - Follow-up queue threshold (hard-coded 7 days vs Settings)
   - Reminder email delivery (queue-driven with scheduled dispatch vs scheduled artisan command sweeping the table)
4. **Propose a Phase 5 task breakdown.** 10 to 16 ordered steps with tests interleaved.

### Specific things I want done well

- **The pipeline must feel like a real workflow, not a CRM.** Drag a card, status changes, history is logged, follow-up timers reset. No 17 fields to fill out before saving.
- **Per-stage notes must never lose data.** Once written, that note exists forever, tied to that stage of that application. Even if the Role gets archived, the notes survive.
- **Calendar Events must be UTC-canonical.** Store UTC, display in user TZ. iCal export preserves original timezone via TZID. This is the kind of thing that bites you in 6 months if not done right.
- **iCal feed must be cacheable and lightweight.** Don't regenerate from scratch on every fetch. Cache for 5 minutes; invalidate on event create/update/delete.
- **Templates should be plain markdown, no fancy editor.** A textarea is fine. Variables via Mustache-style `{{name}}` substitution at copy-time.
- **Reminder delivery must be reliable.** Use Laravel's scheduled jobs, not "fire at scheduled time" hacks. If my dyno restarts at the wrong moment, the reminder still goes out.

### Out of scope explicitly (resist the urge)

- Don't integrate with Gmail, Outlook, or any IMAP server. Logbook only.
- Don't write to external calendars. One-way iCal feed only.
- Don't build a "draft this email for me" feature with or without LLM.
- Don't add an interview question bank.
- Don't add a salary negotiation walkthrough.
- Don't build a referral-network graph view (this gets into LinkedIn integration territory).
- Don't add anything to the signals layer from Phase 4 — that's done.

Ready? Read everything, reflect on your actual usage so far, then respond.

=== PROMPT END ===
```

---

## What to watch for in Claude Code's response

- **Did it ground the plan in your actual usage?** If the response is generic and doesn't reflect your real workflow, push for that grounding.
- **Did it ask about per-stage notes design clearly?** This is the trickiest data model choice in the phase.
- **Did it flag the timezone complexity in calendar events?** If not, raise it. This will bite you otherwise.
- **Did it ask about iCal caching?** Calendar clients refetch aggressively. Caching matters.
