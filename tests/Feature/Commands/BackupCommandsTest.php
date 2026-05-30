<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Mail\BackupFailedMail;
use App\Models\Setting;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Covers the nightly backup lifecycle commands: backup:run (pg_dump → disk,
 * failure alert), backup:prune (retention tiering), and backup:verify
 * (pg_restore --list, empty-TOC alert, skip toggle).
 *
 * Process and Storage are faked so the suite never shells out to a real
 * Postgres binary or touches S3 — tests assert the right archive keys are
 * written / pruned and that pg_restore --list is invoked.
 */
final class BackupCommandsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['slug' => 'primary']);
        app(TenantContext::class)->setCurrent($this->tenant);

        Config::set('backup.disk', 'backups');
        Storage::fake('backups');
    }

    /**
     * Fake pg_dump so it materializes the --file= target (the manager reads it
     * back to put on the disk) and reports success.
     */
    private function fakeSuccessfulDump(): void
    {
        Process::fake(function (PendingProcess $process): \Illuminate\Process\FakeProcessResult {
            $command = $process->command;

            if (is_array($command)) {
                foreach ($command as $arg) {
                    if (is_string($arg) && str_starts_with($arg, '--file=')) {
                        file_put_contents(substr($arg, strlen('--file=')), 'PGDMP-fake-archive');
                    }
                }
            }

            return Process::result(output: '');
        });
    }

    #[Test]
    public function run_writes_a_timestamped_daily_backup_to_the_disk(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-30 02:00:00', 'UTC'));
        $this->fakeSuccessfulDump();

        $exit = Artisan::call('backup:run');

        $this->assertSame(0, $exit);

        $files = Storage::disk('backups')->files('backups/daily');
        $this->assertCount(1, $files);
        $this->assertStringStartsWith('backups/daily/2026-05-30-', $files[0]);
        $this->assertStringEndsWith('.dump', $files[0]);
        $this->assertSame('PGDMP-fake-archive', Storage::disk('backups')->get($files[0]));

        CarbonImmutable::setTestNow();
    }

    #[Test]
    public function run_invokes_pg_dump_with_the_target_database(): void
    {
        Config::set('database.connections.pgsql.database', 'vantagejobs');
        $this->fakeSuccessfulDump();

        Artisan::call('backup:run');

        Process::assertRan(function (PendingProcess $process): bool {
            $command = $process->command;

            return is_array($command)
                && in_array('vantagejobs', $command, true)
                && $command[0] === 'pg_dump';
        });
    }

    #[Test]
    public function run_alerts_and_fails_when_pg_dump_exits_non_zero(): void
    {
        Mail::fake();
        User::factory()->create(['email' => 'ops@example.test']);

        Process::fake([
            '*' => Process::result(errorOutput: 'connection refused', exitCode: 1),
        ]);

        $exit = Artisan::call('backup:run');

        $this->assertSame(1, $exit);
        $this->assertSame([], Storage::disk('backups')->files('backups/daily'));
        Mail::assertQueued(
            BackupFailedMail::class,
            fn (BackupFailedMail $mail): bool => $mail->operation === 'run' && $mail->hasTo('ops@example.test'),
        );
    }

    #[Test]
    public function run_failure_alert_prefers_the_notification_email_setting(): void
    {
        Mail::fake();
        User::factory()->create(['email' => 'login@example.test']);
        Setting::factory()->key('notification_email', 'alerts@example.test')->create();

        Process::fake(['*' => Process::result(errorOutput: 'boom', exitCode: 1)]);

        Artisan::call('backup:run');

        Mail::assertQueued(
            BackupFailedMail::class,
            fn (BackupFailedMail $mail): bool => $mail->hasTo('alerts@example.test'),
        );
    }

    #[Test]
    public function run_failure_skips_the_alert_when_no_recipient_exists(): void
    {
        Mail::fake();
        // No users, no notification_email setting.
        Process::fake(['*' => Process::result(errorOutput: 'boom', exitCode: 1)]);

        $exit = Artisan::call('backup:run');

        $this->assertSame(1, $exit);
        Mail::assertNothingQueued();
    }

    #[Test]
    public function prune_keeps_recent_dailies_one_weekly_and_one_monthly(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-30 02:00:00', 'UTC'));
        Config::set('backup.retention.daily_days', 30);
        Config::set('backup.retention.weekly_weeks', 12);

        $disk = Storage::disk('backups');

        // Inside the daily window (kept regardless).
        $recentA = 'backups/daily/2026-06-29-020000.dump';
        $recentB = 'backups/daily/2026-06-28-020000.dump';

        // Inside the weekly window, same ISO week — only the newest is kept.
        $weekNewer = 'backups/daily/2026-05-20-020000.dump';
        $weekOlder = 'backups/daily/2026-05-19-020000.dump';

        // Outside both windows, same calendar month — only the newest is kept.
        $monthNewer = 'backups/daily/2026-01-20-020000.dump';
        $monthOlder = 'backups/daily/2026-01-10-020000.dump';

        foreach ([$recentA, $recentB, $weekNewer, $weekOlder, $monthNewer, $monthOlder] as $key) {
            $disk->put($key, 'archive');
        }

        $exit = Artisan::call('backup:prune');
        $this->assertSame(0, $exit);

        $remaining = $disk->files('backups/daily');
        sort($remaining);

        $this->assertContains($recentA, $remaining);
        $this->assertContains($recentB, $remaining);
        $this->assertContains($weekNewer, $remaining);
        $this->assertContains($monthNewer, $remaining);
        $this->assertNotContains($weekOlder, $remaining);
        $this->assertNotContains($monthOlder, $remaining);

        CarbonImmutable::setTestNow();
    }

    #[Test]
    public function verify_runs_pg_restore_list_against_the_latest_backup(): void
    {
        Mail::fake();
        Config::set('backup.verify_monthly', true);

        $disk = Storage::disk('backups');
        $disk->put('backups/daily/2026-05-01-020000.dump', 'old');
        $disk->put('backups/daily/2026-05-30-020000.dump', 'latest');

        Process::fake([
            '*' => Process::result(output: "; Archive created\n2200; TABLE companies\n2201; TABLE events\n"),
        ]);

        $exit = Artisan::call('backup:verify');

        $this->assertSame(0, $exit);
        Process::assertRan(function (PendingProcess $process): bool {
            $command = $process->command;

            return is_array($command) && in_array('--list', $command, true);
        });
        Mail::assertNothingQueued();
    }

    #[Test]
    public function verify_alerts_and_fails_on_an_empty_table_of_contents(): void
    {
        Mail::fake();
        User::factory()->create(['email' => 'ops@example.test']);
        Config::set('backup.verify_monthly', true);

        Storage::disk('backups')->put('backups/daily/2026-05-30-020000.dump', 'latest');

        // Only TOC header comment lines — no archive entries.
        Process::fake(['*' => Process::result(output: "; Archive: foo\n; dbname: bar\n")]);

        $exit = Artisan::call('backup:verify');

        $this->assertSame(1, $exit);
        Mail::assertQueued(
            BackupFailedMail::class,
            fn (BackupFailedMail $mail): bool => $mail->operation === 'verify' && $mail->hasTo('ops@example.test'),
        );
    }

    #[Test]
    public function verify_alerts_and_fails_when_pg_restore_exits_non_zero(): void
    {
        Mail::fake();
        User::factory()->create(['email' => 'ops@example.test']);
        Config::set('backup.verify_monthly', true);

        Storage::disk('backups')->put('backups/daily/2026-05-30-020000.dump', 'latest');
        Process::fake(['*' => Process::result(errorOutput: 'unsupported version', exitCode: 1)]);

        $exit = Artisan::call('backup:verify');

        $this->assertSame(1, $exit);
        Mail::assertQueued(BackupFailedMail::class);
    }

    #[Test]
    public function verify_is_a_no_op_when_there_is_no_backup(): void
    {
        Mail::fake();
        Config::set('backup.verify_monthly', true);

        $exit = Artisan::call('backup:verify');

        $this->assertSame(0, $exit);
        Mail::assertNothingQueued();
        Process::assertNothingRan();
    }

    #[Test]
    public function verify_skips_when_monthly_verification_is_disabled(): void
    {
        Mail::fake();
        Config::set('backup.verify_monthly', false);
        Storage::disk('backups')->put('backups/daily/2026-05-30-020000.dump', 'latest');

        $exit = Artisan::call('backup:verify');

        $this->assertSame(0, $exit);
        Process::assertNothingRan();
        Mail::assertNothingQueued();
    }

    #[Test]
    public function commands_run_unauthenticated_as_scheduled_jobs(): void
    {
        $this->assertGuest();
        $this->fakeSuccessfulDump();

        $exit = Artisan::call('backup:run');

        $this->assertSame(0, $exit);
    }
}
