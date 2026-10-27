<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('districts', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            // Tuman mas'ul xodimining telefoni (faqat oxirgi 9 raqam). Bir nechta tumanda bir xil bo'lishi mumkin.
            $table->string('phone', 9)->nullable()->index();
            $table->timestamps();
        });

        Schema::create('mahallas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('district_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();

            $table->unique(['district_id', 'name']);
        });

        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('district_id')->constrained()->restrictOnDelete();
            $table->foreignId('mahalla_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('source_no')->nullable();
            $table->string('address', 500);
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->string('violator_name')->nullable();
            $table->text('violator_passport')->nullable(); // encrypted cast
            $table->text('org_inn')->nullable();           // encrypted cast
            $table->decimal('area_m2', 12, 2)->nullable();
            $table->boolean('is_tutash')->default(true);
            $table->date('court_date')->nullable();
            $table->string('court_number')->nullable();
            $table->string('court_status')->nullable();
            $table->string('status', 20)->default('not_demolished'); // not_demolished | demolished
            $table->date('demolished_at')->nullable();
            $table->timestamp('last_inspected_at')->nullable();
            $table->timestamps();

            $table->unique(['district_id', 'source_no']);
            $table->index(['district_id', 'mahalla_id', 'status']);
        });

        Schema::create('staff', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('telegram_id')->unique();
            $table->string('username')->nullable();
            $table->string('full_name')->nullable();
            $table->string('phone', 9)->nullable()->index();
            $table->string('status', 20)->default('registering'); // registering|awaiting_phone|active|rejected|blocked
            $table->unsignedSmallInteger('failed_attempts')->default(0);
            $table->timestamp('registered_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::create('district_staff', function (Blueprint $table) {
            $table->foreignId('district_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->primary(['district_id', 'staff_id']);
        });

        Schema::create('inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            $table->foreignId('staff_id')->constrained('staff')->restrictOnDelete();
            $table->string('status', 20)->default('draft'); // draft | submitted
            $table->string('step', 30)->default('location');
            $table->boolean('is_demolished')->nullable();
            $table->text('reason')->nullable();
            $table->decimal('staff_lat', 10, 7)->nullable();
            $table->decimal('staff_lng', 10, 7)->nullable();
            $table->unsignedInteger('distance_m')->nullable();
            $table->boolean('suspicious')->default(false);
            $table->json('flags')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'status']);
            $table->index(['staff_id', 'status']);
        });

        // Bir xodimda bir vaqtda bitta qoralama (draft) bo'lishi mumkin.
        // Partial unique index faqat PostgreSQL/SQLite'da ishlaydi; boshqa drayverlarda ilova darajasida tekshiriladi.
        if (in_array(Schema::getConnection()->getDriverName(), ['pgsql', 'sqlite'], true)) {
            Schema::getConnection()->statement(
                "CREATE UNIQUE INDEX inspections_one_draft_per_staff ON inspections (staff_id) WHERE status = 'draft'"
            );
        }

        Schema::create('inspection_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inspection_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20); // before_photo | before_video | after_photo | after_video
            $table->string('telegram_file_id');
            $table->string('file_unique_id')->unique(); // bir xil faylni qayta ishlatishni oldini oladi
            $table->string('path')->nullable();
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->timestamp('downloaded_at')->nullable();
            $table->timestamps();

            $table->unique(['inspection_id', 'kind']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('telegram_id')->nullable()->index();
            $table->string('event', 50)->index();
            $table->json('context')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        foreach (['audit_logs', 'inspection_media', 'inspections', 'district_staff', 'staff', 'sites', 'mahallas', 'districts'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
