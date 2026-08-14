<?php

use Illuminate\Support\Facades\DB;

/**
 * The suite itself runs on :memory:, where journal_mode is always "memory" and
 * WAL is impossible. These open a real file with the app's own sqlite settings,
 * which is the only way to prove the pragmas reach the database rather than
 * just sitting in a config array.
 */
function walConnection(): string
{
    $path = storage_path('framework/testing/pragma-check.sqlite');

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }

    foreach ([$path, $path.'-wal', $path.'-shm'] as $file) {
        if (file_exists($file)) {
            unlink($file);
        }
    }

    touch($path);

    config()->set('database.connections.pragma_check', [
        ...(array) config('database.connections.sqlite'),
        'database' => $path,
    ]);

    DB::purge('pragma_check');

    return 'pragma_check';
}

function forgetWalConnection(): void
{
    DB::purge('pragma_check');

    $path = storage_path('framework/testing/pragma-check.sqlite');

    foreach ([$path, $path.'-wal', $path.'-shm'] as $file) {
        if (file_exists($file)) {
            unlink($file);
        }
    }
}

it('opens a file database in WAL mode', function () {
    $connection = walConnection();

    $mode = DB::connection($connection)->select('PRAGMA journal_mode')[0]->journal_mode ?? null;

    // Rollback-journal mode blocks writers behind readers, which is what was
    // failing calendar syncs outright.
    expect(strtolower((string) $mode))->toBe('wal');

    forgetWalConnection();
});

it('waits rather than failing when the database is busy', function () {
    $connection = walConnection();

    $row = DB::connection($connection)->select('PRAGMA busy_timeout')[0];
    $timeout = $row->timeout ?? ($row->busy_timeout ?? 0);

    expect((int) $timeout)->toBeGreaterThan(0);

    forgetWalConnection();
});

it('keeps the pragmas configured rather than left unset', function () {
    // A regression guard: these were null, which is why none of them applied.
    expect(config('database.connections.sqlite.journal_mode'))->toBe('WAL')
        ->and(config('database.connections.sqlite.busy_timeout'))->toBeGreaterThan(0)
        ->and(config('database.connections.sqlite.synchronous'))->toBe('NORMAL');
});
