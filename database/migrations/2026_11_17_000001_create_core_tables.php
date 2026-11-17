<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name', 120);
            $t->string('username', 40)->unique();
            $t->string('password');
            $t->string('role', 10); // admin | tu | kepsek | guru
            $t->string('nip', 30)->nullable();
            $t->boolean('is_active')->default(true);
            $t->rememberToken();
            $t->timestamp('last_login_at')->nullable();
            $t->timestamps();
        });

        Schema::create('school_classes', function (Blueprint $t) {
            $t->id();
            $t->string('name', 30)->unique();
            $t->unsignedTinyInteger('grade')->default(10);
            $t->foreignId('homeroom_teacher_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('students', function (Blueprint $t) {
            $t->id();
            $t->string('nis', 30)->unique();
            $t->string('nisn', 30)->nullable();
            $t->string('name', 120)->index();
            $t->char('gender', 1)->default('L');
            $t->foreignId('class_id')->nullable()->constrained('school_classes')->nullOnDelete();
            $t->string('qr_code', 16)->unique();
            $t->string('parent_phone', 20)->nullable();
            $t->string('status', 10)->default('aktif'); // aktif | lulus | pindah | keluar
            $t->timestamps();
        });

        Schema::create('leaves', function (Blueprint $t) {
            $t->id();
            $t->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $t->char('kind', 1); // I | S | D
            $t->date('date_from');
            $t->date('date_to');
            $t->string('reason', 255);
            $t->string('attachment')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['student_id', 'date_from', 'date_to']);
        });

        Schema::create('attendances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $t->foreignId('class_id')->nullable()->constrained('school_classes')->nullOnDelete();
            $t->date('date');
            $t->char('status', 1); // H T S I D A
            $t->dateTime('check_in_at')->nullable();
            $t->dateTime('check_out_at')->nullable();
            $t->unsignedSmallInteger('late_minutes')->default(0);
            $t->string('source', 10)->default('scan'); // scan | manual | izin | auto
            $t->foreignId('leave_id')->nullable()->constrained('leaves')->nullOnDelete();
            $t->boolean('corrected')->default(false);
            $t->string('note', 200)->nullable();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['student_id', 'date']);
            $t->index(['class_id', 'date']);
            $t->index(['date', 'status']);
        });

        Schema::create('holidays', function (Blueprint $t) {
            $t->id();
            $t->date('date')->unique();
            $t->string('name', 120);
            $t->timestamps();
        });

        Schema::create('settings', function (Blueprint $t) {
            $t->string('key', 60)->primary();
            $t->text('value')->nullable();
        });
    }

    public function down(): void
    {
        foreach (['settings', 'holidays', 'attendances', 'leaves', 'students', 'school_classes', 'users'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
