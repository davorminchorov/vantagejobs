<?php

declare(strict_types=1);

use App\Scrapers\Aggregator\HackerNews\HackerNewsContextBuilder;
use App\Scrapers\Aggregator\HackerNews\JobSeekerDetector;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Why a listing was auto-dismissed at ingest. Null for the employer
 * auto-dismiss rule (the original meaning of the status); `job_seeker` for
 * job seekers posting in the HackerNews "Who is Hiring" thread.
 *
 * Backfill: HackerNews listings still untriaged (new / overflow) that the
 * detector flags move to auto_dismissed. Statuses the user set (kept,
 * dismissed, snoozed) and system states with their own meaning (linked,
 * duplicate, expired) are left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aggregator_listings', function (Blueprint $table): void {
            $table->string('auto_dismiss_reason')->nullable()->after('status');
        });

        $builder = new HackerNewsContextBuilder();

        DB::table('aggregator_listings')
            ->join('aggregator_sources', 'aggregator_sources.id', '=', 'aggregator_listings.source_id')
            ->where('aggregator_sources.type', 'hackernews')
            ->whereIn('aggregator_listings.status', ['new', 'overflow'])
            ->select(['aggregator_listings.id', 'aggregator_listings.raw'])
            ->orderBy('aggregator_listings.id')
            ->chunk(500, function ($rows) use ($builder): void {
                $ids = [];
                foreach ($rows as $row) {
                    $raw = json_decode((string) $row->raw, true);
                    if (! is_array($raw)) {
                        continue;
                    }

                    $context = $builder->build([
                        'id' => $raw['id'] ?? null,
                        'text' => is_string($raw['text'] ?? null) ? $raw['text'] : '',
                        'created_at' => is_string($raw['created_at'] ?? null) ? $raw['created_at'] : null,
                    ]);

                    if ($context->autoDismissReason === JobSeekerDetector::REASON) {
                        $ids[] = $row->id;
                    }
                }

                if ($ids !== []) {
                    DB::table('aggregator_listings')->whereIn('id', $ids)->update([
                        'status' => 'auto_dismissed',
                        'auto_dismiss_reason' => JobSeekerDetector::REASON,
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        DB::table('aggregator_listings')
            ->where('auto_dismiss_reason', JobSeekerDetector::REASON)
            ->where('status', 'auto_dismissed')
            ->update(['status' => 'new']);

        Schema::table('aggregator_listings', function (Blueprint $table): void {
            $table->dropColumn('auto_dismiss_reason');
        });
    }
};
