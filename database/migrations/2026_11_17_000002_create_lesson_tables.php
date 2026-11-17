<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subjects', function (Blueprint $t) {
            $t->id();
            $t->string('name', 80)->unique();
            $t->timestamps();
        });

        Schema::create('periods', function (Blueprint $t) {
            $t->id();
            $t->unsignedTinyInteger('number')->unique();
            $t->string('start_time', 5);
            $t->string('end_time', 5);
            $t->timestamps();
        });

        // Satu sesi pelajaran = satu kelas, satu mapel, satu rentang jam pada satu tanggal.
        Schema::create('lessons', function (Blueprint $t) {
            $t->id();
            $t->foreignId('class_id')->constrained('school_classes')->cascadeOnDelete();
            $t->foreignId('subject_id')->nullable()->constrained('subjects')->nullOnDelete();
            $t->foreignId('teacher_id')->nullable()->constrained('users')->nullOnDelete();
            $t->date('date');
            $t->unsignedTinyInteger('period_start');
            $t->unsignedTinyInteger('period_end');
            $t->string('topic', 200)->nullable();
            $t->timestamps();
            $t->index(['class_id', 'date']);
            $t->index(['teacher_id', 'date']);
        });

        Schema::create('lesson_attendances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('lesson_id')->constrained('lessons')->cascadeOnDelete();
            $t->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $t->char('status', 1); // H T S I D A
            $t->dateTime('scanned_at')->nullable();
            $t->string('note', 200)->nullable();
            $t->timestamps();
            $t->unique(['lesson_id', 'student_id']);
            $t->index('student_id');
        });
    }

    public function down(): void
    {
        foreach (['lesson_attendances', 'lessons', 'periods', 'subjects'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
