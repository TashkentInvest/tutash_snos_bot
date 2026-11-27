<?php

namespace Tests\Feature;

use App\Models\District;
use App\Models\Inspection;
use App\Models\Site;
use App\Models\Staff;
use App\Models\User;
use App\Support\ReportPdf;
use App\Support\ReportPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SergiX44\Nutgram\Nutgram;
use Tests\TestCase;

/** Kunlik PDF hisobot: tumanlar svodi, davrdagi tekshiruvlar, har oluvchiga ismi bilan. */
class ReportPdfTest extends TestCase
{
    use RefreshDatabase;

    public function test_pdf_has_summary_and_district_inspections_and_is_sent_personally(): void
    {
        $d = District::create(['name' => 'Chilonzor tumani', 'phone' => '901000001']);
        District::create(['name' => 'Yunusobod tumani', 'phone' => '901000002']);
        $staff = Staff::create(['telegram_id' => 7001, 'full_name' => 'Karimov Ali', 'phone' => '907000001', 'status' => Staff::ACTIVE, 'role' => Staff::ROLE_EMPLOYEE]);
        $s1 = Site::create(['district_id' => $d->id, 'source_no' => 1, 'address' => 'Qatortol ko‘chasi, 5-uy', 'violator_name' => '"SAVDO PLUS" MCHJ', 'lat' => 41.3, 'lng' => 69.2]);
        Site::create(['district_id' => $d->id, 'source_no' => 2, 'address' => 'Bunyodkor, 7', 'lat' => 41.3, 'lng' => 69.2]);
        $period = ReportPeriod::current();
        Inspection::forceCreate(['site_id' => $s1->id, 'staff_id' => $staff->id, 'status' => 'submitted', 'step' => 'done', 'is_demolished' => true,
            'review_status' => 'approved', 'submitted_at' => $period->from->copy()->addHour()]);

        $data = ReportPdf::data($period->day);
        $row = $data['rows']->firstWhere('name', 'Chilonzor tumani');
        $this->assertSame([1, 1], [$row['checked'], $row['demolished_now']]);
        $this->assertSame(1, $data['total']['checked']);

        $pdf = ReportPdf::render($period->day, 'Ahmedov Hayitalijon');
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertGreaterThan(5000, strlen($pdf));

        // HTML ichidagi matn (PDF siqilgan — ko'rinishni Blade orqali tekshiramiz)
        $html = view('pdf.daily-report', $data + ['day' => $period->day, 'name' => 'Ahmedov Hayitalijon', 'qr' => '', 'gerb' => null, 'now' => now(),
            'badge' => fn ($i) => ['Buzilgan', '#fff', '#000']])->render();
        foreach (['KUNLIK HISOBOT', 'Hurmatli AHMEDOV HAYITALIJON!', 'Tumanlar kesimida', 'Chilonzor tumani', 'Qatortol ko‘chasi, 5-uy', '&quot;SAVDO PLUS&quot; MCHJ', 'Karimov Ali', 'Buzilgan'] as $s) {
            $this->assertStringContainsString($s, $html);
        }

        $bot = Nutgram::fake();
        $this->assertSame(1, ReportPdf::send($bot, $period->day, collect([$staff])));
        $body = (string) collect($bot->getRequestHistory())->first()['request']->getBody();
        $this->assertStringContainsString('hisobot-'.$period->day->toDateString().'.pdf', $body);

        // panel: faqat admin yuklab oladi
        $admin = (new User)->forceFill(['name' => 'A', 'email' => 'a@t.test', 'password' => 'secret-pass-1', 'is_admin' => true]);
        $admin->save();
        $this->actingAs($admin)->get(route('admin.report.pdf'))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $viewer = (new User)->forceFill(['name' => 'V', 'email' => 'v@t.test', 'password' => 'secret-pass-2', 'is_viewer' => true]);
        $viewer->save();
        $this->actingAs($viewer)->get(route('admin.report.pdf'))->assertForbidden();
    }
}
