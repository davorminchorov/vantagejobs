@component('mail::message')
@if ($operation === 'verify')
# Backup verification failed

The monthly restore check could not read the latest backup archive.
@else
# Backup run failed

Tonight's database backup did not complete.
@endif

**What failed:**

@component('mail::panel')
{{ $reason }}
@endcomponent

@if (! empty($backupKey))
**Archive:** `{{ $backupKey }}`
@endif

@if ($operation === 'verify')
A failing verification means the most recent dump may not be restorable. Pull the archive down and run `pg_restore --list` by hand to confirm, and re-run the nightly backup if needed.
@else
No new off-platform copy of the database was written tonight. Check the `pg_dump` binary, the backup disk credentials, and the database connection, then re-run `backup:run`.
@endif

Thanks,<br>
{{ config('app.name') }}
@endcomponent
