<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Testlarda Dizayn-kod boti bazasi: xotiradagi sqlite, sxema — botning o'zinikidan (tests/fixtures/dizayn-schema.sql).
 * setUp() da $this->setUpDizaynDb() chaqiriladi.
 */
trait UsesDizaynDb
{
    protected function setUpDizaynDb(): void
    {
        config(['database.connections.dizayn' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false],
            'services.dizayn_bot.enabled' => true]);
        DB::purge('dizayn');
        DB::connection('dizayn')->unprepared(file_get_contents(base_path('tests/fixtures/dizayn-schema.sql')));
    }

    /** Bitta qator qo'shib, id qaytaradi (jadvalda «id» bo'lsa). */
    protected function dz(string $table, array $row): int
    {
        DB::connection('dizayn')->table($table)->insert($row);

        return (int) DB::connection('dizayn')->getPdo()->lastInsertId();
    }
}
