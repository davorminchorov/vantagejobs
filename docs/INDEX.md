# Phase Kickoff Prompts — Index

You now have 7 kickoff prompts, one per phase of the build, plus this index. Use them in order. Each one starts a fresh Claude Code session.

## The phases

| Phase | File | What ships |
|---|---|---|
| 0 | `claude-code-kickoff-prompt.md` | Foundation: scaffold, auth, schema, empty dashboard |
| 1 | `claude-code-phase-1-kickoff.md` | ATS scrapers (Greenhouse, Lever, Ashby), Companies UI, Cmd-K, CSV import |
| 2 | `claude-code-phase-2-kickoff.md` | Aggregator sources (HN, Remotive, WWR, Himalayas, RemoteOK), parser pipeline, triage queue, bookmarks |
| 3 | `claude-code-phase-3-kickoff.md` | Parser correction loop, run history, parser stats, advanced filtering, Saved Views |
| 4 | `claude-code-phase-4-kickoff.md` | Signals: snapshots, trend charts, layoffs, GitHub activity, blogs, funding, ghost detection, quality score, graveyard |
| 5 | `claude-code-phase-5-kickoff.md` | Pipeline kanban, per-stage notes, offer tracking, contacts, templates, calendar, iCal feed |
| 6 | `claude-code-phase-6-kickoff.md` | Digest emails, A-tier alerts, source health, backups, exports, accessibility, docs |

## The workflow

For each phase:

1. **Finish the previous phase.** Make sure the ship criterion is genuinely met. Don't fudge this.
2. **Write phase notes.** Drop a short `docs/PHASE_N_NOTES.md` covering what got built, deviations from the PRD, and any tech debt carried. This is what the next phase reads to understand the codebase state.
3. **Update the PRD if anything changed.** The PRD is a living document. If a Phase 4 decision invalidates something in section 6 of the PRD, update section 6.
4. **Start a fresh Claude Code session.** Don't reuse the previous one. Context drift kills coherence.
5. **Paste the kickoff prompt** for the next phase (the content between `=== PROMPT START ===` and `=== PROMPT END ===`).
6. **Answer Claude Code's questions in batch.** Group by topic for clarity. Don't rush.
7. **Review the proposed task list before approving.** Edit it. Cut things that don't matter. Add things you've realized matter.
8. **Work through steps one at a time.** Stop at each gate. Actually review. Don't auto-approve.
9. **At the end, write the phase notes.** Loop.

## Common pitfalls

- **Carrying one Claude Code session across phases.** Don't. Context drift will produce worse and worse output.
- **Skipping phase notes.** The next phase's kickoff explicitly reads them. Skipping means starting cold.
- **Auto-approving every step.** The interactive pattern only works if you actually review.
- **Letting a phase expand.** When tempted to add "just one more thing" from Phase N+1, write it down in your phase notes as a TODO and move on. Ship the phase.
- **Treating the PRD as immutable.** It's a living document. Update it when reality diverges. Future-you needs the doc to match the code.
- **Believing the ship criteria are optional.** "I can log in with 2FA" is binary. Either I can or I can't. If I can't, Phase 0 isn't done.

## When you're done

After Phase 6 ships, you're done with v1. The success metrics from PRD section 4 are behavioral, not technical:

- I check the dashboard daily without it feeling like a chore
- 80% of senior/staff roles I interview for came through the platform first
- Triage queue stays under 50 unread items
- Average "company posts new role" → "I see it" under 24 hours

Those are observations you make over the *next* 6–12 months. Don't build a Phase 7 to extend v1. Use it. Learn. Then decide if v2 is worth building.

## Files in this bundle

### Project documentation (copy these into `docs/` and `/` of your project)

- `README.md` — front door for the project. Lives at the repo root.
- `prd-v2.md` — the source of truth for *what* and *why*. Lives at `docs/PRD.md`.
- `SOURCES.md` — engineering reference for every data source. Lives at `docs/SOURCES.md`.
- `ARCHITECTURE.md` — high-level system design and conventions. Lives at `docs/ARCHITECTURE.md`.
- `RUNBOOK.md` — operational playbook for when things break. Lives at `docs/RUNBOOK.md`.
- `sourcing-map.html` — the rendered research dossier. Optional; read-only reference material. Lives at `docs/sourcing-map.html` if you want it in the repo.

### Claude Code prompts (paste these into Claude Code one phase at a time)

- `claude-code-kickoff-prompt.md` — Phase 0.
- `claude-code-phase-1-kickoff.md` through `phase-6-kickoff.md` — Phases 1–6.

### Index

- `INDEX.md` — this file.

## Recommended project layout

```
your-project/
├── README.md               <- copy from README.md
├── docs/
│   ├── PRD.md              <- copy from prd-v2.md
│   ├── SOURCES.md          <- copy from SOURCES.md
│   ├── ARCHITECTURE.md     <- copy from ARCHITECTURE.md
│   ├── RUNBOOK.md          <- copy from RUNBOOK.md
│   ├── sourcing-map.html   <- optional; copy from sourcing-map.html
│   ├── PHASE_0_NOTES.md    <- write after Phase 0 ships
│   ├── PHASE_1_NOTES.md    <- write after Phase 1 ships
│   └── ...
├── app/
├── resources/
└── ...
```

All five core docs (README, PRD, SOURCES, ARCHITECTURE, RUNBOOK) are living documents. Update them as decisions evolve. The phase notes are append-only — each phase has its own file, written at the end of the phase and never edited after.

Good luck. Ship the boring version first.
