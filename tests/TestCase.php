<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /** Sozlamalar → Hisobotlar: berilgan xodimlarni barcha (yoki $keys) hisobotlarning oluvchisi qiladi. */
    protected function receivesReports(array $staff, ?array $keys = null): void
    {
        foreach ($keys ?? array_keys(\App\Models\ReportSetting::catalog()) as $key) {
            $setting = \App\Models\ReportSetting::for($key);
            $ids = array_values(array_unique([...($setting->recipients ?? []), ...array_map(fn ($s) => (int) (is_object($s) ? $s->id : $s), $staff)]));
            $setting->update(['recipients' => $ids]);
        }
    }
}
