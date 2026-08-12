# Sources Reference

**Purpose:** This is the engineering reference for every data source integrated (or planned to be integrated) into the platform. Use this as the canonical truth when building scrapers. The PRD describes *what* and *why*; this doc describes *how*.

**Format:** Each source has the same structure. If a field is unknown or doesn't apply, the entry says so explicitly.

**Maintenance:** Update this doc whenever a source's API changes, a rate limit shifts, or a new field becomes available. Treat it as living documentation, not a one-time research deliverable.

**Companion docs:**
- `docs/PRD.md` — feature scope and decisions
- `docs/PHASE_N_NOTES.md` — what was actually built in each phase

---

## Source classification

Sources fall into three categories:

1. **ATS public APIs** — per-company JSON/XML endpoints. One slug per tracked Company. Direct from employer.
2. **Aggregator sources** — many-companies-per-fetch feeds. Output is freeform and requires parsing. Surfaces companies I don't yet track.
3. **Signal sources** — non-job data sources used for company-quality enrichment (layoffs, funding, GitHub activity, eng blogs).

## Phase assignment

| Phase | Sources |
|---|---|
| 1 | Greenhouse, Lever, Ashby |
| 2 | HN "Who is Hiring", Remotive, We Work Remotely, Himalayas, RemoteOK |
| 4 | layoffs.fyi, TrueUp, GitHub org activity, eng-blog RSS, Google News (funding), yc-oss YC mirror |
| 6 (if needed) | Workable, SmartRecruiters, Personio, Recruitee, Teamtailor, 4dayweek.io, Working Nomads, Adzuna |
| Never | LinkedIn API, Indeed API, Glassdoor scraping, Wellfound API, Crunchbase API, X/Twitter API |

## Cross-cutting guidance

**HTTP client defaults (all sources):**
- User-Agent: `JobsBot/1.0 (+https://yourdomain.tld; contact@yourdomain.tld)` — identify yourself
- Connection: `keep-alive`, TCP keepalive enabled at curl level
- Timeout: 15s default, 5s connect-timeout
- Retries: exponential backoff (1s, 2s, 4s, 8s), max 4 retries
- Respect `Retry-After` headers when 429 occurs
- Send `If-None-Match` / `If-Modified-Since` when caching ETag / Last-Modified

**Per-source concurrency (in the Guzzle Pool):**
- Greenhouse / Lever / Ashby: 10 concurrent requests (different customer subdomains)
- Workable / SmartRecruiters / Personio / Recruitee / Teamtailor: 5
- Remotive: 1 (strict rate limit)
- HN Algolia: serial, monthly batch
- All others: 1–2

**Legal:** All sources below are integrated under their public terms. Attribution required where noted. Never scrape sources that prohibit it (LinkedIn, Indeed, Glassdoor, Wellfound).

---

# Category 1: ATS Public APIs

## 1.1 Greenhouse

**Verdict:** Must-have, priority #1.

**Phase:** 1.

**Type:** Per-company JSON API, no auth required for GETs.

**Endpoints:**
- List all jobs for a company:
  `GET https://boards-api.greenhouse.io/v1/boards/{board_token}/jobs?content=true`
- Single job:
  `GET https://boards-api.greenhouse.io/v1/boards/{board_token}/jobs/{job_id}`
- Departments:
  `GET https://boards-api.greenhouse.io/v1/boards/{board_token}/departments`
- Offices:
  `GET https://boards-api.greenhouse.io/v1/boards/{board_token}/offices`

**Finding the `board_token`:**
- The token is the slug in `https://boards.greenhouse.io/{board_token}` or `https://job-boards.greenhouse.io/{board_token}`.
- Many companies embed it: look for `<iframe src="https://boards.greenhouse.io/embed/job_board?for={board_token}">` on the careers page.
- Detection regex: `(?:boards|job-boards)\.greenhouse\.io/(?:embed/job_board\?for=)?([a-z0-9_-]+)`.

**Auth:** None for GET. POST (application submission) requires HTTP Basic with a Job Board API key — out of scope for v1.

**Rate limits:** Effectively unlimited for reasonable use. Cached aggressively on Greenhouse's side. Be polite anyway: cache `ETag` / `Last-Modified` and send conditional requests.

**Response shape (jobs list):**
```json
{
  "jobs": [
    {
      "id": 12345,
      "title": "Senior Backend Engineer",
      "absolute_url": "https://job-boards.greenhouse.io/{token}/jobs/12345",
      "location": { "name": "Remote — Americas" },
      "departments": [{ "id": 1, "name": "Engineering", "child_ids": [], "parent_id": null }],
      "offices": [{ "id": 1, "name": "Remote", "location": null, "child_ids": [], "parent_id": null }],
      "metadata": [
        { "id": 1, "name": "Salary Range", "value": "180000-240000", "value_type": "currency_range" }
      ],
      "content": "<p>HTML job description...</p>",
      "updated_at": "2026-05-10T14:32:00.000Z",
      "requisition_id": "ENG-123"
    }
  ],
  "meta": { "total": 42 }
}
```

**Parsing notes:**
- `location.name` is single free-text. Always parse heuristically. Common shapes: `"Remote — Americas"`, `"EU Remote"`, `"San Francisco, CA"`, `"Remote"`.
- `metadata[]` is free-form per customer — you cannot rely on a `salary` key existing. When it exists, the entry usually has `name: "Salary Range"` and `value` in cents or thousands; verify per customer.
- `content` is HTML. Strip tags for body parsing; preserve for display.
- `requisition_id` is your stable ID for boomerang detection (PRD section 6.3.7).

**Gotchas:**
- Some customers expose only a subset of departments/offices publicly. Don't assume completeness.
- Greenhouse Harvest API (the private API for ATS integration) migrates from Basic Auth (v1/v2) to JWT/OAuth2 (v3) by **31 August 2026**. **The public Job Board API used here is unaffected.**
- A job can disappear from the list without being closed — sometimes it's moved to a private board. Detection: if a Role disappears from the feed without a subsequent re-appearance within 14 days, treat as closed.

**Coverage:** Stripe, Airbnb, Discord, Vercel, HashiCorp, Cloudflare, dbt Labs, Coinbase, Anthropic, OpenAI, GitLab, Reddit, Robinhood, Brex, Ramp, Notion, Linear, and most well-funded US-startup tech.

---

## 1.2 Lever

**Verdict:** Must-have.

**Phase:** 1.

**Type:** Per-company JSON API, no auth required.

**Endpoint:**
- List postings:
  `GET https://api.lever.co/v0/postings/{site}?mode=json`

**Finding `{site}`:**
- The slug in `https://jobs.lever.co/{site}`.
- EU instance: parallel URL pattern; detect by following careers-page link.
- Detection regex: `jobs\.lever\.co/([a-z0-9_-]+)`.

**Server-side filters:**
- `team`, `department`, `location`, `commitment`, `level`, `skip`, `limit`

**Auth:** None for GET. The full Lever API (for write access) requires OAuth — out of scope.

**Rate limits:** Not documented for the public postings endpoint. In practice, polite usage (1 request per company per scrape run) is fine. Concurrency: 10 across different customer subdomains.

**Response shape:**
```json
[
  {
    "id": "abc-123-def",
    "text": "Senior Backend Engineer",
    "categories": {
      "team": "Engineering",
      "location": "Remote — US/EU",
      "commitment": "Full-time",
      "department": "Engineering",
      "level": "Senior"
    },
    "hostedUrl": "https://jobs.lever.co/{site}/abc-123-def",
    "applyUrl": "https://jobs.lever.co/{site}/abc-123-def/apply",
    "description": "<p>HTML description...</p>",
    "lists": [
      { "text": "Responsibilities", "content": "<ul>...</ul>" },
      { "text": "Requirements", "content": "<ul>...</ul>" }
    ],
    "additional": "<p>Optional extra HTML...</p>",
    "createdAt": 1715000000000,
    "workplaceType": "remote",
    "salaryRange": {
      "currency": "USD",
      "interval": "per-year",
      "min": 180000,
      "max": 240000
    }
  }
]
```

**Parsing notes:**
- `workplaceType` is the **most reliable remote-flag of any ATS**. Values: `unspecified | on-site | remote | hybrid`. Trust it.
- `salaryRange` only present when the customer entered structured data. A minority today, but growing thanks to NYC/CA pay-transparency laws. Capture eagerly when present.
- `categories.location` is free-text, like Greenhouse.
- `lists[]` is structured — pull `Responsibilities` and `Requirements` for the parser pipeline.

**Gotchas:**
- `createdAt` is a Unix millisecond timestamp, not seconds.
- The `text` field is the title; `description` is the body. Easy to confuse on first integration.

**Coverage:** Mixpanel, Eventbrite, Auth0, Lattice, Plaid, Replit, Netflix (historically), and many mid-stage US/EU startups.

---

## 1.3 Ashby

**Verdict:** Must-have. Best-structured compensation data of any free ATS.

**Phase:** 1.

**Type:** Per-company JSON API, no auth required.

**Endpoint:**
- List postings with compensation:
  `GET https://api.ashbyhq.com/posting-api/job-board/{name}?includeCompensation=true`

**Finding `{name}`:**
- The slug in `https://jobs.ashbyhq.com/{name}`.
- Detection regex: `jobs\.ashbyhq\.com/([a-z0-9_-]+)`.

**Auth:** None for GET. POST requires Ashby API key (HTTP Basic, key as username).

**Rate limits:** Not formally documented for the public endpoint. Polite usage is fine. Concurrency: 10.

**Response shape:**
```json
{
  "apiVersion": "1",
  "jobs": [
    {
      "id": "abc123",
      "title": "Staff Backend Engineer",
      "location": "Remote",
      "secondaryLocations": ["Europe", "EMEA"],
      "department": "Engineering",
      "team": "Platform",
      "isListed": true,
      "isRemote": true,
      "workplaceType": "Remote",
      "descriptionHtml": "<p>...</p>",
      "descriptionPlain": "...",
      "publishedAt": "2026-05-01T00:00:00.000Z",
      "employmentType": "FullTime",
      "address": {
        "postalAddress": {
          "addressLocality": "San Francisco",
          "addressRegion": "CA",
          "addressCountry": "US"
        }
      },
      "jobUrl": "https://jobs.ashbyhq.com/{name}/abc123",
      "applyUrl": "https://jobs.ashbyhq.com/{name}/abc123/application",
      "compensation": {
        "compensationTierSummary": "$200k-$260k + equity",
        "tiers": [
          {
            "id": "tier1",
            "tierSummary": "$200k-$260k",
            "components": [
              {
                "compensationType": "Salary",
                "interval": "1 YEAR",
                "currencyCode": "USD",
                "minValue": 200000,
                "maxValue": 260000
              },
              {
                "compensationType": "EquityPercentage",
                "minValue": 0.05,
                "maxValue": 0.15
              }
            ]
          }
        ]
      }
    }
  ]
}
```

**Parsing notes:**
- `isRemote` is a boolean, separate from `workplaceType`. Use `workplaceType` as the primary signal.
- `secondaryLocations[]` often contains region tags like `Europe`, `EMEA`, `Americas` — gold for CET filtering (PRD section 8.8).
- `compensation.tiers[]` may have multiple tiers (e.g., per-level for Senior/Staff/Principal). Capture all; surface the highest by default.
- `employmentType` values: `FullTime`, `PartTime`, `Contract`, `Intern`.

**Gotchas:**
- `compensation` is only populated when the customer enables structured comp. Most do. Use `includeCompensation=true` in the URL.
- `descriptionHtml` and `descriptionPlain` are both returned; prefer `descriptionPlain` for parsing.

**Coverage:** Linear, Ramp, Vanta, Modal, Mercury, Replicate, Posthog, and disproportionately many YC W22+ well-funded startups. **Weight Ashby-sourced roles higher in scoring** — the customer base skews high-quality.

---

# Category 2: Aggregator Sources

## 2.1 Hacker News "Who is Hiring"

**Verdict:** Must-have. Unique signal — founder-posted senior/staff remote roles.

**Phase:** 2.

**Type:** Aggregator, freeform text. Monthly batch.

**Endpoints:**
- Find the latest thread:
  `GET https://hn.algolia.com/api/v1/search?query=Ask+HN+Who+is+hiring&tags=story,author_whoishiring&hitsPerPage=5`
- Fetch full thread (recursively):
  `GET https://hn.algolia.com/api/v1/items/{story_id}`
- Front search (alternate):
  `GET https://hn.algolia.com/api/v1/search?tags=story,author_whoishiring`

**Algolia HN is free, no auth, no key.** Use Algolia, not the Firebase HN API — Algolia gives you the entire thread tree in one call.

**Schedule:** Monthly. The thread by `whoishiring` posts on the 1st business day of each month. Run on the 3rd of each month and again on the 5th to catch late additions.

**Response shape (thread tree from `/items/{id}`):**
```json
{
  "id": 12345678,
  "title": "Ask HN: Who is hiring? (May 2026)",
  "author": "whoishiring",
  "created_at": "2026-05-01T15:00:00Z",
  "children": [
    {
      "id": 12345700,
      "author": "founder_jane",
      "text": "<p>Vercel | Staff Engineer, Edge | Remote (US/EU) | $220k-$280k + equity<br>...full posting body...</p>",
      "created_at": "2026-05-01T15:30:00Z",
      "children": []
    }
  ]
}
```

**Parsing approach:**
- Each top-level child is a posting. Use the comment ID as the dedup key.
- The text starts with HTML escaped. Unescape first.
- The first line typically follows: `Company | Role | Location | REMOTE | $range | tech stack | URL`
- Split on `|` first, then `–`, then `—`, then ` - `.
- See PRD section 6.1.4 for the full parser-extractor design.

**Filter heuristics for top-level posts:**
- Skip if deleted or dead
- Skip if text length < 100 characters
- Skip if author is `whoishiring` (the OP)

**Companion threads (parse identically):**
- "Who wants to be hired?" — candidates, not employers. Skip for v1 unless we expose it.
- "Freelancer? Seeking freelancer?" — freelance gigs. Skip for v1 unless we expose it.

**Volume:** ~300–500 employer postings per monthly thread in 2026 (down from ~636/month long-run average). HNHiring.com indexed 59,196 ads from Jan 2018 – Oct 2025.

**Gotchas:**
- HN format drifts. Some posters use Markdown-ish syntax, some plain prose. The parser must degrade gracefully.
- Companies often post to multiple months. Cross-source dedup (PRD section 6.4.7) handles this.
- Algolia HN can be a few hours stale. Not real-time.

**Backup sources (if Algolia fails):**
- `https://hnhiring.com/` — community mirror, parsed and searchable
- `https://hacker-news.firebaseio.com/v0/item/{id}.json` — Firebase HN, requires recursive fetching of children

---

## 2.2 Remotive

**Verdict:** Must-have.

**Phase:** 2.

**Type:** Aggregator, structured JSON.

**Endpoint:**
- `GET https://remotive.com/api/remote-jobs?category=software-dev`

**Filters:**
- `category=software-dev` (use this — don't fetch all categories)
- `company_name=...` (optional)
- `search=...` (optional)
- `limit=N` (default 100)

**Auth:** None.

**Rate limits:** **Strict.** ≤ 2 requests per minute or you get blocked. Remotive's own guidance: ≤ 4 polls per day. **Concurrency: 1.** Schedule once daily.

**Response shape:**
```json
{
  "0-legal-notice": "Remote jobs by Remotive (https://remotive.com)...",
  "job-count": 124,
  "jobs": [
    {
      "id": 1234567,
      "url": "https://remotive.com/remote-jobs/software-dev/role-slug-1234567",
      "title": "Senior Backend Engineer",
      "company_name": "Acme Corp",
      "company_logo": "https://remotive.com/.../logo.png",
      "category": "Software Development",
      "tags": ["python", "django", "postgres"],
      "job_type": "full_time",
      "publication_date": "2026-05-12T10:00:00",
      "candidate_required_location": "Worldwide",
      "salary": "$150,000 - $200,000",
      "description": "<p>HTML description...</p>"
    }
  ]
}
```

**Parsing notes:**
- `salary` is a free-form string. Parse heuristically.
- `candidate_required_location` is structured-ish: common values include `Worldwide`, `USA Only`, `EMEA`, `Europe`, `Americas`.
- `tags[]` is high quality — merge with parser-extracted stack tags but trust Remotive's first.
- `publication_date` has no timezone — treat as UTC.

**Gotchas:**
- Data is **delayed 24h** to favor Remotive as the canonical source.
- ToS forbids republishing without attribution.
- ToS forbids resubmitting to Google Jobs / LinkedIn / Jooble / Neuvoo. (Personal use is fine.)
- First element of the response array is the legal notice — skip it.

---

## 2.3 Himalayas

**Verdict:** Must-have. Best timezone data of any aggregator — critical for CET-based filtering.

**Phase:** 2.

**Type:** Aggregator, structured JSON.

**Endpoints:**
- Browse all:
  `GET https://himalayas.app/jobs/api?offset=0&limit=20`
- Search with filters:
  `GET https://himalayas.app/jobs/api/search?keyword=...&country=...&worldwide=...&seniority=...&employment_type=...&company=...&timezone=...&sort=...`
- OpenAPI spec:
  `https://himalayas.app/docs/openapi.json`
- RSS fallback (100 most recent):
  `https://himalayas.app/jobs/rss`

**Auth:** None.

**Rate limits:** Not numeric; returns 429 if abused. Cache refreshed every 24h on their side, so daily polling is fine. For higher volume, email `hi@himalayas.app`.

**Schedule:** Once daily.

**Response shape (search):**
```json
{
  "jobs": [
    {
      "guid": "company-slug-role-slug",
      "title": "Staff Fullstack Engineer",
      "companyName": "Acme",
      "companySlug": "acme",
      "applicationLink": "https://acme.com/jobs/staff-fs",
      "locationRestrictions": ["Worldwide"],
      "timezoneRestrictions": ["UTC+01:00", "UTC+02:00"],
      "categories": ["Software Engineering"],
      "seniority": ["Senior"],
      "employmentType": ["Full-Time"],
      "salaryMin": 180000,
      "salaryMedian": 220000,
      "salaryMax": 260000,
      "salaryCurrency": "USD",
      "description": "...sanitized HTML...",
      "publishedDate": "2026-05-12T00:00:00Z"
    }
  ],
  "total": 14
}
```

**Parsing notes:**
- `timezoneRestrictions[]` is the headline feature for a Skopje-based user. Values look like `UTC+01:00`, `UTC+02:00`, `Americas`, `EMEA`.
- `salaryMin / salaryMedian / salaryMax` already USD-normalized.
- `seniority` and `employmentType` are arrays — usually single-element but built for multi.

**CET filter tip:** Use `timezone=UTC+01:00` or `timezone=UTC+02:00` in the search query to pre-filter at the source.

**Volume:** ~110k active jobs (May 2026, per their own page).

**Gotchas:**
- The OpenAPI spec is the source of truth — check it when adding new query params.
- `salaryMedian` is a Himalayas-computed value, not from the employer. Use with caution.

---

## 2.4 We Work Remotely

**Verdict:** Nice-to-have via RSS. Partner API exists but requires a deal.

**Phase:** 2.

**Type:** Aggregator, RSS (XML).

**RSS endpoints (public, no auth):**
- All remote jobs:
  `https://weworkremotely.com/remote-jobs.rss`
- Back-end:
  `https://weworkremotely.com/categories/remote-back-end-programming-jobs.rss`
- Full-stack:
  `https://weworkremotely.com/categories/remote-full-stack-programming-jobs.rss`
- All programming:
  `https://weworkremotely.com/categories/remote-programming-jobs.rss`

**Authenticated API (partner-only):**
- `https://weworkremotely.com/api/v1/remote-jobs` — Bearer token from `hello@weworkremotely.com`
- 1,000 requests/day per authenticated user
- Skip unless we formalize a partner agreement

**Schedule:** Once daily.

**RSS item shape:**
```xml
<item>
  <guid isPermaLink="false">https://weworkremotely.com/remote-jobs/acme-corp-senior-backend-engineer</guid>
  <title>Acme Corp: Senior Backend Engineer</title>
  <region>Worldwide</region>
  <link>https://weworkremotely.com/remote-jobs/acme-corp-senior-backend-engineer</link>
  <description><![CDATA[<p>HTML description...</p>]]></description>
  <pubDate>Mon, 12 May 2026 10:00:00 +0000</pubDate>
</item>
```

**Parsing notes:**
- Title format: `Company: Role Title`. Split on the first colon.
- `region` is structured-ish: `Worldwide`, `Anywhere in the World`, `USA Only`, `Europe`, `EMEA`.
- Description contains an HTML-encoded blob with the full posting. Strip tags for parsing.

**Gotchas:**
- ToS specifically prohibits scraping outside the RSS or API. Stick to RSS for v1.
- No salary data in the RSS feed.
- No structured tags.

---

## 2.5 RemoteOK

**Verdict:** Nice-to-have. Tech-heavy, lots of crypto/Web3 noise.

**Phase:** 2.

**Type:** Aggregator, structured JSON.

**Endpoint:**
- `GET https://remoteok.com/api`

**Auth:** None.

**Rate limits:** Not documented; polite daily polling is fine. Concurrency: 1.

**Schedule:** Once daily.

**Response shape:**
```json
[
  { "legal": "...", "warning": "..." },
  {
    "id": "abc123",
    "slug": "abc123-acme-senior-backend",
    "company": "Acme Corp",
    "company_logo": "https://remoteok.com/.../logo.png",
    "position": "Senior Backend Engineer",
    "tags": ["python", "django", "remote"],
    "logo": "...",
    "description": "<p>HTML...</p>",
    "location": "Worldwide",
    "salary_min": 150000,
    "salary_max": 200000,
    "apply_url": "https://...",
    "url": "https://remoteok.com/remote-jobs/abc123",
    "date": "2026-05-12T10:00:00+00:00"
  }
]
```

**Parsing notes:**
- **The first element of the response array is metadata/legal**. Skip it.
- `tags[]` is high-quality.
- `salary_min` / `salary_max` already normalized; treat as USD unless otherwise noted.
- `location` is free-text but commonly `Worldwide`, `Europe`, `USA`.

**Gotchas:**
- Self-claim 30,000+ listings, but many are reposts.
- Crypto/Web3 density is high. Plan for stack-tag filtering at the platform level (filter on `tag:crypto` to exclude if desired).

---

# Category 3: Phase-6 Optional Aggregators

These are integrated only if Phase 1+2 coverage proves insufficient. Each follows the same shape; check the deep research dossier for full details before implementing.

## 3.1 Workable
- **Public widget API:** `GET https://apply.workable.com/api/v1/widget/accounts/{subdomain}`
- **Account JSON:** `GET https://www.workable.com/api/accounts/{subdomain}`
- **Coverage:** EU SMBs, UK, Greece-heavy (Workable HQ).
- **Concurrency:** 5.

## 3.2 SmartRecruiters
- **Endpoint:** `GET https://api.smartrecruiters.com/v1/companies/{companyIdentifier}/postings`
- **Filters:** `q`, `country`, `region`, `city`, `department`, `language`, `limit`, `offset`
- **Coverage:** Mid-market enterprise EU.
- **Concurrency:** 5 (their authenticated APIs cap at 10 req/sec global).

## 3.3 Personio (DACH-heavy, useful for EU)
- **Endpoint:** `GET https://{company}.jobs.personio.de/xml?language=en`
- **Format:** XML, not JSON.
- **Coverage:** 15,000+ customers per Personio's 2025 report; default ATS for German/Austrian/Swiss SMBs.
- **Languages:** `de`, `en`, `fr`, `es`, `nl`, `it`, `pt`
- **Gotcha:** Some customers use `.com` instead of `.de` — probe the live careers page.
- **Concurrency:** 5.

## 3.4 Recruitee
- **Endpoint:** `GET https://{company}.recruitee.com/api/offers/`
- **Coverage:** Dutch ATS popular in EU SMBs.
- **Concurrency:** 5.

## 3.5 Teamtailor
- **Endpoint:** Per-customer XML feed when enabled.
- **Authenticated JSON:** `GET https://api.teamtailor.com/v1/jobs` with `Authorization: Token token=...` and `X-Api-Version: 20240404` (customer-only).
- **Coverage:** Nordic-heavy (Klarna, Tink, Voi).

## 3.6 4dayweek.io
- **Type:** Aggregator. RSS and API exposed in site footer.
- **URL:** `https://4dayweek.io/remote-jobs`
- **Coverage:** ~2,500 jobs, curated for reduced-schedule employers.

## 3.7 Working Nomads
- **Endpoint:** `GET https://www.workingnomads.co/api/exposed_jobs/`
- **Type:** JSON, no auth.

## 3.8 Adzuna
- **Endpoint:** `GET https://api.adzuna.com/v1/api/jobs/{country}/search/{page}?app_id={ID}&app_key={KEY}`
- **Free tier:** Generous, requires app_id/app_key registration.
- **Coverage:** 12+ countries; useful for EU geo-discovery.

## 3.9 Pinpoint
- **Verdict:** Integrated (verified live 2026-08-12). Officially documented public JSON endpoint.
- **Endpoint:** `GET https://{slug}.pinpointhq.com/postings.json`
- **Docs:** https://developers.pinpointhq.com/docs/jobs-json-endpoint (replaces the deprecated `jobs.json`).
- **Auth:** None. **Pagination:** None — single response, filter query params only.
- **Detection:** `{slug}.pinpointhq.com`
- **Response shape:** `{"data":[...]}`; items carry full HTML description/benefits, `workplace_type` (`remote|hybrid|onsite`), structured compensation (`compensation_minimum/maximum/currency/frequency`) gated by `compensation_visible`, department under `job.department.name`.
- **Conditional GET:** Honours `If-None-Match` (weak ETags, verified 304). No `Last-Modified`.
- **Gotchas:** No posted/created date field — `postedAt` is always null. `location.name` can be null; fall back to `location.city`.

## 3.10 BambooHR
- **Verdict:** Integrated (verified live 2026-08-12). Undocumented but widely used public careers JSON.
- **Endpoint:** `GET https://{slug}.bamboohr.com/careers/list`
- **Auth:** None. **Pagination:** None — single response (`{"meta":{"totalCount":N},"result":[...]}`).
- **Detection:** `{slug}.bamboohr.com` (excluding `www`).
- **Response shape:** list items carry `id`, `jobOpeningName`, `departmentLabel`, `employmentStatusLabel`, `location.{city,state}`, `atsLocation`, `isRemote`, `locationType`. No description and no posted date — `postedAt` is null; job URL is constructed as `https://{slug}.bamboohr.com/careers/{id}`.
- **Gotchas:** Invalid subdomains 302-redirect to the `www.bamboohr.com` marketing site — the connector disables redirect following and the scraper maps 3xx to a slug-not-found error (`http_status: 404`) so redetect health tracking engages. `isRemote` is null on every posting observed live; `locationType === "1"` correlates with no-fixed-city roles and is treated as a remote signal.

## 3.11 Rippling
- **Verdict:** Integrated (verified live 2026-08-12). Public board API, no auth.
- **Endpoint:** `GET https://ats.rippling.com/api/v2/board/{slug}/jobs?page={n}&pageSize=100`
- **Auth:** None. **Pagination:** zero-based `page` with `totalPages` metadata; 10-page safety cap with truncation reporting.
- **Detection:** `ats.rippling.com/{slug}/jobs` (board pages) or the API path itself.
- **Response shape:** `{"items":[...]}`; items carry uuid `id`, `name`, `url`, `department.name`, structured `locations[].{name,country,countryCode}`. No comp fields, no posted date.
- **Gotchas:** No ETag/Last-Modified observed live — conditional-GET plumbing is forward-compatible only. Board slugs often end in `-job-board` (e.g. `cars-and-bids-job-board`).

## 3.12 Gem
- **Verdict:** Integrated (verified live 2026-08-12). Officially documented public JSON endpoint.
- **Endpoint:** `GET https://api.gem.com/job_board/v0/{slug}/job_posts/` (boards live at `jobs.gem.com/{slug}`)
- **Docs:** https://help.gem.com/databases/gem-help-center/the-job-board-api
- **Auth:** None. **Pagination:** None — bare JSON array, no cursor/meta fields.
- **Detection:** `jobs.gem.com/{slug}` or the API path.
- **Response shape:** Greenhouse-like posts: `id` (string, numeric or opaque base64), `title`, `absolute_url`, `content`/`content_plain`, `departments[]`, `offices[]` with nested `location.name`, `location_type` (`remote|hybrid|in_office`), `employment_type`, `requisition_id`, `created_at`/`updated_at`/`first_published_at`.
- **Gotchas:** Unknown vanity path returns a 404 JSON object; a valid board with zero postings returns `200 []` (handled by the mass-close guard). No structured comp — comp language only appears inline in HTML content.

---

# Category 4: Signal Sources (Phase 4)

## 4.1 layoffs.fyi

**Verdict:** Must-have negative signal.

**Phase:** 4.

**Type:** Airtable view embedded on homepage.

**Access:**
- Primary: scrape the embedded Airtable view at `https://layoffs.fyi/` (URL rotates; build defensively)
- Kaggle mirror: `theakhilb/layoffs-data-2022` (refreshed periodically)
- Reach out to Roger Lee (maintainer) for bulk data sharing — historically he's open to it

**Schedule:** Weekly.

**Fields (typical):**
- `Company`, `Location_HQ`, `# Laid Off`, `Date`, `% Layoff`, `Industry`, `Source`, `Stage`, `Country`, `Funds_Raised` (in millions)

**Parsing notes:**
- Match against tracked Companies by normalized name (case-insensitive, suffix-stripped).
- Severity = `# Laid Off` and `% Layoff` (when known).
- Each match creates an Event of type `layoff` on the Company.
- Affects the negative side of the quality score.

**Gotchas:**
- Mildly hostile to consume. Airtable embed URL rotates. Build with fallback (Kaggle mirror) and alert when scrape fails 3+ days running.
- Company-name normalization is critical. "Acme Inc.", "Acme, Inc", "Acme Corp" should all match the tracked "Acme".

---

## 4.2 TrueUp

**Verdict:** Companion negative-signal source.

**Phase:** 4.

**Type:** Web scrape.

**URL:** `https://www.trueup.io/layoffs`

**No documented API.** Scrape weekly. Includes by-sector aggregate stats useful for the global trend view.

**Fallback role:** When layoffs.fyi is down, TrueUp is the secondary source.

---

## 4.3 GitHub Org Activity

**Verdict:** Must-have for eng-culture signal.

**Phase:** 4.

**Type:** GitHub REST API.

**Endpoints:**
- Org metadata:
  `GET https://api.github.com/orgs/{org}`
  Returns: `public_repos`, `created_at`, `description`, `blog`, `followers`
- Recent repos (by push time):
  `GET https://api.github.com/orgs/{org}/repos?sort=pushed&direction=desc&per_page=10`
- Repo activity (per repo):
  `GET https://api.github.com/repos/{org}/{repo}/commits?since={ISO_date}&per_page=100`

**Auth:** Use a personal access token in `GITHUB_TOKEN` env var. Authenticated rate limit: **5,000 requests/hour**. Anonymous: 60/hour (don't bother).

**Schedule:** Weekly per tracked Company with a known GitHub org.

**Discovery of `{org}`:**
- Manual entry on the Company in v1 (PRD allows for auto-detection in Phase 6).
- Auto-detect heuristic: fetch company website, look for `<link rel="me" href="https://github.com/...">` or footer/contact links.

**Activity metric (composite, per company):**
- `public_repos_count` — base size
- Pushes in last 90 days across top 10 repos — activity intensity
- New repos created in last 90 days — innovation signal
- Contributor count growth — team scaling

Roll up into a single 0–100 "eng activity" score with trend arrow (up/flat/down vs last week).

**Gotchas:**
- Some companies have multiple orgs (Microsoft, Google). Pick the most-relevant one manually.
- Personal accounts ≠ company orgs. Filter out user accounts.
- The `pushed_at` timestamp on a repo doesn't always reflect human activity (bots, dependabot). Filter by commit author when computing real activity.

---

## 4.4 Engineering Blog RSS

**Verdict:** Must-have for eng-culture signal.

**Phase:** 4.

**Type:** Per-company RSS feed.

**Endpoint:** Per company. Common paths:
- `{website}/blog/feed.xml`
- `{website}/blog/rss.xml`
- `{website}/engineering/feed`
- `{website}/feed/atom.xml`
- Substack-hosted: `{subdomain}.substack.com/feed`
- Medium: `medium.com/feed/{publication}`

**Discovery aggregators:**
- `https://engineeringblogs.xyz/` — community list with RSS URLs
- `https://github.com/kilimchoi/engineering-blogs` — 300+ companies

**Schedule:** Daily.

**Auth:** None.

**Parsing:**
- Standard RSS / Atom. Use a parser library (PHP: `simplepie/simplepie` or `Feed-IO`).
- For each new item (not in DB), create an Event of type `blog_post` on the Company with `title`, `url`, `published_at`.
- Index posts for the existing Cmd-K search (Phase 1).

**Cadence metric:**
- Posts in last 90 days = primary signal
- Monthly+ cadence with technical depth (sniff for `<pre>` / `<code>` ratio in description) = green flag
- Zero posts in 180 days = negative signal

**Gotchas:**
- RSS feeds rot. Build with retry and a "this feed has been failing for 14 days" alert.
- Some companies hide their RSS behind login (rare but exists).
- Medium feeds are aggressive about caching; respect `Last-Modified`.

---

## 4.5 Google News RSS (Funding Detection)

**Verdict:** Lightweight funding tracker.

**Phase:** 4.

**Type:** Google News RSS search.

**Endpoint:**
- `https://news.google.com/rss/search?q={company_name}+raised+OR+Series+OR+funding&hl=en-US&gl=US&ceid=US:en`

**Schedule:** Weekly per tracked Company.

**Auth:** None.

**Parsing:**
- Each `<item>` is a news result.
- Filter by keywords in `<title>`: `raised`, `Series A/B/C/D`, `funding round`, `closes round`.
- Create Events of type `funding` with the news article URL.
- Manual review needed before updating Company funding fields (heuristic-only, false-positives are common).

**Gotchas:**
- Company name collisions (Acme Corp vs Acme Tech Solutions) produce wrong-company matches. Always link back to the source article.
- Google News may rate-limit aggressive RSS polling. Weekly is fine.

---

## 4.6 yc-oss YC Company Mirror

**Verdict:** Must-have seed source for the watch list.

**Phase:** 1 (for seeding), 4 (for funding/status updates).

**Type:** Static JSON, rebuilt daily by GitHub Actions.

**Endpoints:**
- Index of all endpoints:
  `https://yc-oss.github.io/api/meta.json`
- All YC companies:
  `https://yc-oss.github.io/api/companies/all.json`
- Actively hiring:
  `https://yc-oss.github.io/api/companies/hiring.json`
- Per batch (e.g., W26):
  `https://yc-oss.github.io/api/batches/w26.json`

**Auth:** None.

**Rate limits:** Static files on GitHub Pages — effectively unlimited.

**Schedule:** Weekly refresh.

**Response shape (per company):**
```json
{
  "name": "Acme",
  "former_names": [],
  "small_logo_thumb_url": "https://...",
  "website": "https://acme.com",
  "all_locations": "San Francisco",
  "long_description": "Acme builds...",
  "one_liner": "AI for X",
  "team_size": 12,
  "industry": "B2B",
  "subindustry": "B2B -> Developer Tools",
  "launched_at": 1700000000,
  "tags": ["Developer Tools", "Generative AI"],
  "tags_highlighted": [],
  "top_company": false,
  "isHiring": true,
  "nonprofit": false,
  "batch": "W26",
  "status": "Active",
  "industries": ["B2B"],
  "regions": ["United States of America"],
  "stage": "Early",
  "url": "https://www.ycombinator.com/companies/acme"
}
```

**Use cases:**
- **Phase 1:** Seed the watch list from `companies/hiring.json` filtered by batch ≥ W22.
- **Phase 4:** Track `isHiring` and `status` changes over time as company-health signal.

**Gotchas:**
- `top_company: true` is YC's curation; weight accordingly.
- `status` values: `Active`, `Public`, `Acquired`, `Inactive`. Map `Inactive` to graveyard candidacy.

---

# Category 5: Excluded Sources (Do Not Integrate)

The following are documented to prevent future confusion. If something below changes (gets cheaper, gets an open API), revisit.

| Source | Why excluded |
|---|---|
| **LinkedIn Jobs API** | Partner-only, no public access. Scraping ToS-prohibited and aggressively defended. |
| **Indeed API** | Publisher program deprecated 2023. Ghost-job-rich (18–22% per Greenhouse Q4 2024 analysis). |
| **Glassdoor Jobs** | Partner-only. Scraping contentious. Manual lookups only for ratings. |
| **Wellfound / AngelList** | No documented public API. DataDome + Cloudflare anti-bot. Residential proxies needed. Manual only. |
| **Stack Overflow Jobs** | Dead since March 2022. |
| **Hired.com** | Folded into LHH Recruitment Solutions, June 2024. Defunct as a job board. |
| **Honeypot.io** | Wound down 1 January 2025. |
| **TripleByte** | Defunct, 2023. |
| **Pragmatic Engineer Job Board** | Retired 27 April 2024 when Pallet exited the creator-job-board business. |
| **X / Twitter API** | Pay-per-use as of February 2026 ($0.005/read, $0.015/write, no free tier for new developers). Blows the monthly budget. Manual follow-list curation only. |
| **Crunchbase API** | Enterprise-only ($50k+/yr per Vendr). Pro is UI-only at $588/yr. Use YC mirror + OpenVC + Dealroom + layoffs.fyi instead. |
| **FlexJobs** | Paywalled with weak value. |
| **Toptal / Andela / Turing / Crossover / A.Team** | Invite-only matching networks. Sourcing channels for the user, not data feeds. |
| **Lensa / The Muse / SimplyHired / Jooble / Neuvoo** | Re-aggregators with high duplication. |
| **Lenny's Job Board** | PM-only. Wrong vertical for backend/fullstack. |
| **xteamjobs.com** | Unrelated freelancing marketplace, easy name-collision trap. Skip. |
| **X-Team (x-team.com)** | Single employer, not a source. Track as a regular Company if interested; ATS auto-detection will find their careers page. |

---

# Appendix A: Scraper Interface Contract

Every ATS scraper implements `App\Scrapers\Contracts\AtsScraperInterface`; aggregator scrapers implement `App\Scrapers\Aggregator\Contracts\AggregatorScraperInterface`. The real ATS contract (see the interface file, which is authoritative):

```php
interface AtsScraperInterface
{
    /**
     * Identify this scraper (AtsType enum case).
     */
    public function name(): AtsType;

    /**
     * Run the scraper for one company. Must be idempotent: running twice
     * against the same board produces zero new Events the second time.
     * Never throws — errors are returned as data on ScraperRunResult.
     */
    public function run(Company $company): ScraperRunResult;
}
```

Each scraper builds its own Saloon connector (extending `ConfigurableConnector`, which wires retries/timeouts from `config/scrapers.php`) and diffs results through the shared `RoleDiffer`. Scheduling and concurrency live in `routes/console.php` and Horizon config, not on the scraper.

---

# Appendix B: Polling Cadence Cheatsheet

| Source | Cadence | Reason |
|---|---|---|
| Greenhouse / Lever / Ashby (tracked companies) | 6 hours | Direct ATS data, want freshness |
| Workable / Personio / Recruitee / Teamtailor | 12 hours | Lower-volume ATS |
| Pinpoint / BambooHR / Rippling / Gem | 12 hours | Lower-volume ATS |
| Himalayas / Remotive / RemoteOK / WWR RSS / 4dayweek | 24 hours | Aggregator caching + ToS |
| HN "Who is Hiring" | Monthly + day-5 | Thread posts on the 1st |
| layoffs.fyi + TrueUp | Weekly | Negative signals don't move fast |
| GitHub org activity | Weekly | Rate limit + cost vs change rate |
| Engineering blog RSS | Daily | Cheap, low volume |
| Google News (funding) | Weekly | Low volume, low-priority |
| yc-oss mirror | Weekly | Rebuilt daily on their side; weekly diff is enough |

---

# Appendix C: Backup-Path Quick Reference

When a primary source fails:

| Primary | Fallback | Tertiary |
|---|---|---|
| Greenhouse Job Board API | JSON-LD on company careers page | Email the company |
| Lever postings API | JSON-LD on company careers page | — |
| Ashby posting API | JSON-LD on company careers page | — |
| HN Algolia | hnhiring.com mirror | Firebase HN API (recursive) |
| Remotive | Cache previous fetch (24h) | RSS at remotive.com/remote-jobs/feed |
| Himalayas API | RSS at himalayas.app/jobs/rss | — |
| RemoteOK | Cache previous fetch | RSS at remoteok.com/rss |
| WWR RSS | Cache previous fetch | — |
| layoffs.fyi | Kaggle mirror (`theakhilb/layoffs-data-2022`) | TrueUp (different methodology) |
| GitHub org | GitHub web scraping (last resort) | — |
| Engineering blog | Cache previous fetch | Manual check |

---

**Last updated:** May 2026. Update this document whenever a source's API, rate limit, or behavior materially changes.
