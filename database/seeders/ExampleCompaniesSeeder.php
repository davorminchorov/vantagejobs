<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Company;
use App\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

final class ExampleCompaniesSeeder extends Seeder
{
    /**
     * Ten real companies hand-picked from docs/SOURCES.md coverage. Each
     * uses the real public ATS slug so the Phase 1 ingestion workers can
     * hit boards.greenhouse.io/{slug}, api.lever.co/v0/postings/{slug},
     * jobs.ashbyhq.com/{slug} without modification.
     *
     * @var array<int, array<string, string>>
     */
    private const COMPANIES = [
        // Greenhouse — 5
        [
            'name' => 'Stripe',
            'website' => 'https://stripe.com',
            'careers_url' => 'https://boards.greenhouse.io/stripe',
            'domain' => 'stripe.com',
            'ats_type' => 'greenhouse',
            'ats_slug' => 'stripe',
            'tier' => 'A',
            'status' => 'offer',
        ],
        [
            'name' => 'Airbnb',
            'website' => 'https://airbnb.com',
            'careers_url' => 'https://boards.greenhouse.io/airbnb',
            'domain' => 'airbnb.com',
            'ats_type' => 'greenhouse',
            'ats_slug' => 'airbnb',
            'tier' => 'A',
            'status' => 'in_process',
        ],
        [
            'name' => 'Discord',
            'website' => 'https://discord.com',
            'careers_url' => 'https://boards.greenhouse.io/discord',
            'domain' => 'discord.com',
            'ats_type' => 'greenhouse',
            'ats_slug' => 'discord',
            'tier' => 'B',
            'status' => 'watching',
        ],
        [
            'name' => 'Vercel',
            'website' => 'https://vercel.com',
            'careers_url' => 'https://boards.greenhouse.io/vercel',
            'domain' => 'vercel.com',
            'ats_type' => 'greenhouse',
            'ats_slug' => 'vercel',
            'tier' => 'A',
            'status' => 'applied',
        ],
        [
            'name' => 'HashiCorp',
            'website' => 'https://hashicorp.com',
            'careers_url' => 'https://boards.greenhouse.io/hashicorp',
            'domain' => 'hashicorp.com',
            'ats_type' => 'greenhouse',
            'ats_slug' => 'hashicorp',
            'tier' => 'B',
            'status' => 'watching',
        ],

        // Lever — 3
        [
            'name' => 'Mixpanel',
            'website' => 'https://mixpanel.com',
            'careers_url' => 'https://jobs.lever.co/mixpanel',
            'domain' => 'mixpanel.com',
            'ats_type' => 'lever',
            'ats_slug' => 'mixpanel',
            'tier' => 'B',
            'status' => 'researching',
        ],
        [
            'name' => 'Plaid',
            'website' => 'https://plaid.com',
            'careers_url' => 'https://jobs.lever.co/plaid',
            'domain' => 'plaid.com',
            'ats_type' => 'lever',
            'ats_slug' => 'plaid',
            'tier' => 'B',
            'status' => 'watching',
        ],
        [
            'name' => 'Replit',
            'website' => 'https://replit.com',
            'careers_url' => 'https://jobs.lever.co/replit',
            'domain' => 'replit.com',
            'ats_type' => 'lever',
            'ats_slug' => 'replit',
            'tier' => 'C',
            'status' => 'rejected',
        ],

        // Ashby — 2
        [
            'name' => 'Linear',
            'website' => 'https://linear.app',
            'careers_url' => 'https://jobs.ashbyhq.com/linear',
            'domain' => 'linear.app',
            'ats_type' => 'ashby',
            'ats_slug' => 'linear',
            'tier' => 'A',
            'status' => 'watching',
        ],
        [
            'name' => 'Vanta',
            'website' => 'https://vanta.com',
            'careers_url' => 'https://jobs.ashbyhq.com/vanta',
            'domain' => 'vanta.com',
            'ats_type' => 'ashby',
            'ats_slug' => 'vanta',
            'tier' => 'graveyard',
            'status' => 'graveyard',
        ],
    ];

    public function run(): void
    {
        $tenant = app(TenantContext::class)->currentOrFail();

        $created = 0;
        $updated = 0;

        foreach (self::COMPANIES as $row) {
            $existed = Company::withoutTenantScope()
                ->where('tenant_id', $tenant->id)
                ->where('domain', $row['domain'])
                ->exists();

            $attributes = [
                'name' => $row['name'],
                'website' => $row['website'],
                'careers_url' => $row['careers_url'],
                'ats_type' => $row['ats_type'],
                'ats_slug' => $row['ats_slug'],
                'tier' => $row['tier'],
                'status' => $row['status'],
            ];

            if ($row['status'] === 'graveyard') {
                $attributes['is_active'] = false;
            }

            Company::query()->updateOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'domain' => $row['domain'],
                ],
                $attributes,
            );

            $existed ? $updated++ : $created++;
        }

        $this->command?->getOutput()->writeln(
            "  <info>Example companies:</info> {$created} created, {$updated} updated (".count(self::COMPANIES).' total)'
        );
    }
}
