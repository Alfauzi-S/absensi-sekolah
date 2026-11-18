<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Lesson;
use App\Models\LessonAttendance;
use App\Models\Setting;
use App\Models\Subject;
use Tests\TestCase;

class LessonTest extends TestCase
{
    public function test_teacher_opens_lesson_prefilled_from_daily_status_and_marks(): void
    {
        $guru = $this->user('guru');
        $class = $this->classWithStudents('X-1', 3);
        [$a, $b, $c] = $class->students->all();
        Attendance::create(['student_id' => $b->id, 'class_id' => $class->id, 'date' => '2026-11-17', 'status' => 'S', 'source' => 'izin']);
        $math = Subject::create(['name' => 'Matematika']);

        $this->actingAs($guru)->post('/pelajaran', ['class_id' => $class->id, 'subject_id' => $math->id, 'date' => '2026-11-17', 'period_start' => 1, 'period_end' => 2])
            ->assertRedirect();
        $lesson = Lesson::firstOrFail();
        $this->assertSame(['H', 'S', 'H'], [$a, $b, $c] === [] ? [] : array_map(fn ($s) => LessonAttendance::where('student_id', $s->id)->value('status'), [$a, $b, $c]));

        $this->postJson("/pelajaran/{$lesson->id}/tandai", ['student_id' => $c->id, 'status' => 'A'])->assertOk();
        $this->assertSame('A', LessonAttendance::where('student_id', $c->id)->value('status'));
        $this->get("/pelajaran/{$lesson->id}")->assertOk()->assertSee('Matematika');

        // Sesi bentrok di jam yang sama diarahkan ke sesi yang sudah ada.
        $this->post('/pelajaran', ['class_id' => $class->id, 'date' => '2026-11-17', 'period_start' => 2, 'period_end' => 2])
            ->assertRedirect("/pelajaran/{$lesson->id}");
        $this->assertSame(1, Lesson::count());

        // Guru lain tidak boleh mengubah sesi ini.
        $this->actingAs($this->user('guru'))->postJson("/pelajaran/{$lesson->id}/tandai", ['student_id' => $c->id, 'status' => 'H'])->assertForbidden();
    }

    public function test_admin_settings_control_mode_and_access(): void
    {
        $guru = $this->user('guru');
        $other = $this->classWithStudents('X-2', 2);
        $this->actingAs($guru);

        Setting::put('lesson_access', 'homeroom');
        $this->post('/pelajaran', ['class_id' => $other->id, 'date' => '2026-11-17', 'period_start' => 1, 'period_end' => 1])->assertForbidden();

        Setting::put('lesson_access', 'all');
        $this->post('/pelajaran', ['class_id' => $other->id, 'date' => '2026-11-17', 'period_start' => 1, 'period_end' => 1])->assertRedirect();
        $lesson = Lesson::firstOrFail();

        // Scan kartu hanya bila mode QR aktif.
        $student = $other->students->first();
        $this->postJson("/pelajaran/{$lesson->id}/scan", ['code' => $student->qr_code])->assertForbidden();
        Setting::put('lesson_mode', 'qr');
        $this->postJson("/pelajaran/{$lesson->id}/scan", ['code' => $student->qr_code])->assertOk()->assertJson(['ok' => true]);
        $wrong = $this->classWithStudents('X-3', 1)->students->first();
        $this->postJson("/pelajaran/{$lesson->id}/scan", ['code' => $wrong->qr_code])->assertJson(['ok' => false, 'message' => 'Bukan siswa kelas ini.']);

        Setting::put('lesson_mode', 'off');
        $this->get('/pelajaran')->assertNotFound();
        $this->get('/')->assertDontSee('Absensi per Jam');
    }

    public function test_settings_page_saves_lesson_options_subjects_and_periods(): void
    {
        $this->actingAs($this->user('admin'));
        $this->put('/pengaturan', ['school_name' => 'SMA 2', 'checkin_start' => '06:00', 'late_after' => '07:00', 'checkout_start' => '13:30',
            'checkout_end' => '16:00', 'weekend_days' => [7], 'lesson_mode' => 'qr', 'lesson_access' => 'homeroom'])->assertSessionHasNoErrors();
        $this->assertSame(['qr', 'homeroom', '7'], [Setting::get('lesson_mode'), Setting::get('lesson_access'), Setting::get('weekend_days')]);
        $this->put('/pengaturan', ['school_name' => 'SMA 2', 'checkin_start' => '08:00', 'late_after' => '07:00', 'checkout_start' => '13:30',
            'checkout_end' => '16:00', 'lesson_mode' => 'qr', 'lesson_access' => 'all'])->assertSessionHasErrors('late_after');

        $this->post('/pengaturan/mapel', ['subject' => 'Fisika'])->assertSessionHasNoErrors();
        $this->post('/pengaturan/jam', ['start_time' => '07:15', 'end_time' => '08:00']);
        $this->post('/pengaturan/jam', ['start_time' => '08:00', 'end_time' => '08:45']);
        $this->assertSame([1, 2], \App\Models\Period::orderBy('number')->pluck('number')->all());
    }

    public function test_lesson_recap_export(): void
    {
        $this->actingAs($admin = $this->user('admin'));
        $class = $this->classWithStudents();
        $this->post('/pelajaran', ['class_id' => $class->id, 'date' => '2026-11-17', 'period_start' => 1, 'period_end' => 1]);
        $this->get('/pelajaran/rekap?kelas='.$class->id.'&bulan=2026-11')->assertOk()->assertSee('Siswa X-1 1');
        $this->get('/pelajaran/rekap/unduh?kelas='.$class->id.'&bulan=2026-11')->assertOk();
    }
}
