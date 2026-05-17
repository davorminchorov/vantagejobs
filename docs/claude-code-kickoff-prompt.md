# Claude Code Kickoff Prompt — Personal Job-Hunting Platform

---

## How to use

1. Create an empty directory for the project.
2. Set up the documentation by copying these files into the right places:
   - `prd-v2.md` → `docs/PRD.md`
   - `SOURCES.md` → `docs/SOURCES.md`
   - `ARCHITECTURE.md` → `docs/ARCHITECTURE.md`
   - `RUNBOOK.md` → `docs/RUNBOOK.md`
   - `README.md` → `README.md` (at the repo root)
   - Optionally: `sourcing-map.html` → `docs/sourcing-map.html`
3. Open Claude Code in that directory.
4. Paste everything below the `=== PROMPT START ===` line as your first message.
5. Answer Claude Code's questions as they come up.

---

```
=== PROMPT START ===

# Project: Personal Job-Hunting Platform

You're helping me build a personal job-hunting platform from scratch. This is a long-term project I'll iterate on for months. Your job is to be a thoughtful, opinionated collaborator who builds things the right way, not a code-spewing autocomplete.

## Read this first

The project documentation lives in `docs/` and at the repo root. Read all of it before writing any code:

- **`docs/PRD.md`** — Product Requirements Document. The source of truth for *what* and *why*. Read in full. Goals, non-goals, scope, feature set, NFRs, technical stack, decisions log (section 11).
- **`docs/SOURCES.md`** — engineering reference for every data source we'll ever integrate. You won't need most of this until Phase 1, but skim the structure now and read the cross-cutting guidance at the top.
- **`docs/ARCHITECTURE.md`** — high-level system overview, data flow, conventions, deployment plan. This is the template for what the architecture will look like; some sections describe future phases. Treat it as direction.
- **`docs/RUNBOOK.md`** — operational playbook (template form). You'll fill in the real commands as you build.
- **`README.md`** at repo root — the project's front door. Read so you understand how the project will be presented to future-you.

When you've read everything, summarize in 5-8 bullets what you understood as the most important constraints and tradeoffs. I want to verify we're on the same page before we touch any files.

## How we work together

**Interactive, not autonomous.** Before any significant decision — schema design choices, library picks beyond what's in the PRD, file/folder structure, naming conventions, architectural patterns — stop and ask me. I'd rather answer 10 short questions upfront than untangle 10 wrong assumptions later.

**The PRD is direction, not gospel.** Treat it as a strong opinion loosely held. If you spot something that doesn't make sense, contradicts itself, or where a better path exists, flag it and propose the alternative. Don't silently deviate, don't blindly follow. Push back when you have a real reason.

**Prefer fewer, better dependencies.** When you'd otherwise reach for a package, ask whether the standard library, Laravel core, or 20 lines of code would suffice. Each new dep is a long-term tax.

**Boring tech wins.** This is a personal tool I have to maintain for years. Choose stable, well-documented, widely-used options over clever or cutting-edge ones, unless cutting-edge is materially better.

**Show your work.** When you make a non-obvious choice, briefly say why. Two sentences is enough. I want to learn the reasoning, not just see the output.

**Stop and confirm at milestones.** After completing each meaningful chunk of work (initial scaffold, schema, first scraper, first UI page), stop, summarize what you did, and ask whether to continue or adjust. Don't barrel through Phase 0 into Phase 1 without checking in.

## The stack (from the PRD, for quick reference)

- Laravel 13 + PHP 8.5
- FrankenPHP in classic mode (no worker mode in v1)
- PostgreSQL 18
- Inertia.js + React 19 + TypeScript
- Tailwind CSS v4 + shadcn/ui primitives
- Vite for frontend build
- Laravel Fortify for auth (email + password + TOTP 2FA, no public registration)
- Guzzle for HTTP (with Pool for concurrency)
- Cron-driven artisan commands for scraping (no Horizon in v1)
- Deploy target: single VPS (Hetzner), single user

If anything in this stack feels wrong for what we're building, say so before starting.

## What we're building right now: Phase 0

Per PRD section 10, Phase 0 is the foundation:

> Laravel 13 scaffold with FrankenPHP (classic mode), PHP 8.5, Postgres 18, Tailwind v4, Inertia + React + TypeScript with shadcn/ui primitives, base schema for Company, Role, Event, Source, Note, User, AuditLog. Fortify configured with login, password reset, and TOTP 2FA support. Single user seeded from `.env`. Registration route disabled. First-run wizard for empty state. Seed with 10 real companies (mix of Greenhouse, Lever, Ashby). Deploy to VPS with HTTPS. Responsive layout shell.
>
> **Ship criterion:** I can log in, log out, change my password, enable 2FA, and see a placeholder dashboard. The platform is private and exposed to the public internet safely.

Phase 0 does **not** include actual scrapers, parsers, signals, or the triage queue. Those are Phases 1-4. Don't let scope creep pull us forward.

## How to start

Don't write code yet. Don't run `composer create-project` yet. Don't create files yet.

Instead, in this first response, do all of the following:

1. **Confirm you've read the PRD** at `docs/PRD.md`, and summarize the most important constraints and tradeoffs you took away from it (5-8 bullets).
2. **Flag anything in the PRD that surprises you, contradicts itself, or where you'd push back.** If nothing, say so explicitly.
3. **List the questions you need answered before scaffolding** Phase 0. Group them by topic (project structure, auth, schema, deployment, etc.). Don't ask more than you actually need — be precise, not exhaustive. Examples of things I'd expect you to ask about:
   - Repo layout (monorepo vs. separate frontend, where docs live, what to commit)
   - Naming conventions (table names, model names, route names)
   - Auth specifics (lockout policy, recovery code count, session lifetime)
   - Local dev workflow (Sail? Herd? raw php artisan serve? Docker?)
   - Frontend conventions (component organization, naming, css strategy within Tailwind)
   - Testing approach (Pest vs PHPUnit, frontend testing, what to test from day one)
   - Schema specifics (UUID vs autoincrement IDs, soft deletes, naming of pivot tables, audit log shape)
   - Deployment plan (Forge vs. manual, when to provision the VPS, environment management)
   - Anything else genuinely material before we start
4. **Propose a Phase 0 task breakdown** — a checklist of 8 to 15 concrete steps you'd execute in order, each shippable on its own. We'll work through that list together, stopping after each step for me to review.

Once I answer your questions and confirm the task list, you'll start executing one step at a time, pausing at each step for me to review before continuing.

Ready? Read the PRD and respond.

=== PROMPT END ===
```

---

## Notes on what this prompt does

**It forces Claude Code to read the PRD before doing anything.** The first instruction is "Read this first" and the first asked output is "summarize what you understood." This catches misreadings before they become bad code.

**It explicitly authorizes pushback.** The "PRD is direction, not gospel" framing gives Claude Code permission to flag contradictions or suggest alternatives. Without this, models tend to follow specs literally even when something is clearly wrong.

**It blocks premature scaffolding.** The "Don't write code yet" section is critical. Without it, Claude Code will run `composer create-project` in the first turn, lock in dozens of defaults, and you'll spend the next hour undoing them.

**It establishes the interaction pattern from turn one.** Three things in turn one: summary, pushback, questions. That sets the cadence for the whole project — ask, confirm, then execute.

**The question prompt list is illustrative, not prescriptive.** I gave examples of what to ask about so Claude Code knows the depth expected, but it's free to add or skip questions based on what the PRD already answers.

**It scopes Phase 0 explicitly.** "Phase 0 does not include actual scrapers" is there because Claude Code will otherwise try to be helpful and build a Greenhouse scraper "while we're at it." Being firm about phase boundaries protects you from a 4-hour first session that builds everything halfway.

**It asks for a task list before execution.** You'll review the breakdown before any code is written. That's your last chance to catch a misunderstanding cheaply.

## What to do after Claude Code responds

You'll get back something like:

1. A summary of the PRD (verify it matches your understanding)
2. Pushback or noted concerns (engage with these honestly — sometimes Claude Code spots real issues)
3. A list of questions (answer them all in one reply if you can; group your answers by topic)
4. A proposed Phase 0 task breakdown (edit it before approving)

Once you confirm the task list, Claude Code will start executing step by step. Stay engaged at each stop-point — the pattern only works if you actually review at the gates rather than just saying "continue" to everything.

## When to start a new Claude Code session

After Phase 0 ships, start a fresh session for Phase 1 with a similar prompt structure pointing to the same PRD plus a `docs/PHASE_0_NOTES.md` (or git log) summarizing what was built. Each phase is its own implementation conversation. This keeps context tight and prevents drift.
