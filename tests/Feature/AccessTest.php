<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Setting;
use Tests\TestCase;

class AccessTest extends TestCase
{
    public function test_setup_creates_first_admin_once(): void
    {
        $this->get('/login')->assertRedirect('/setup');
        $this->post('/setup', ['school_name' => 'SMA 1', 'name' => 'Admin', 'username' => 'admin', 'password' => 'rahasia123', 'password_confirmation' => 'rahasia123'])
            ->assertRedirect('/');
        $this->assertSame('SMA 1', Setting::get('school_name'));
        $this->post('/logout');
        $this->get('/setup')->assertNotFound();
    }

    public function test_login_and_inactive_user(): void
    {
        $this->user('guru', ['username' => 'budi']);
        $this->user('guru', ['username' => 'nonaktif', 'is_active' => false]);
        $this->post('/login', ['username' => 'nonaktif', 'password' => 'password123'])->assertSessionHasErrors('username');
        $this->post('/login', ['username' => 'budi', 'password' => 'password123'])->assertRedirect('/');
    }

    public function test_every_page_renders_for_admin(): void
    {
        $this->actingAs($this->user('admin'));
        $class = $this->classWithStudents();
        $student = $class->students->first();
        foreach (['/', '/absensi', '/izin', '/izin/baru', '/rekap', '/rekap/ringkasan', '/siswa', '/siswa/baru', "/siswa/{$student->id}",
            "/siswa/{$student->id}/ubah", '/kelas', '/kelas/baru', "/kelas/{$class->id}/ubah", '/kartu?kelas='.$class->id, '/impor',
            '/pengguna', '/pengguna/baru', '/pengaturan', '/akun', '/pelajaran', '/pelajaran/baru', '/pelajaran/rekap', '/dashboard/terbaru'] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get('/rekap/unduh?kelas='.$class->id.'&bulan=2026-11')->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->get('/impor/template')->assertOk();
    }

    public function test_teacher_sees_only_homeroom_class(): void
    {
        $guru = $this->user('guru');
        $mine = $this->classWithStudents('X-1', 2, $guru);
        $other = $this->classWithStudents('X-2', 2);
        $this->actingAs($guru);

        $this->get('/siswa')->assertForbidden();
        $this->get('/pengaturan')->assertForbidden();
        $this->get('/absensi?kelas='.$other->id)->assertOk()->assertSee('Kelas X-1')->assertDontSee('Siswa X-2 1');
        $this->get('/siswa/'.$other->students->first()->id)->assertForbidden();
        $this->get('/rekap/unduh?kelas='.$other->id)->assertForbidden();

        $this->post('/absensi/koreksi', ['student_id' => $other->students->first()->id, 'date' => '2026-11-17', 'status' => 'A'])->assertForbidden();
        $this->postJson('/absensi/koreksi', ['student_id' => $mine->students->first()->id, 'date' => '2026-11-17', 'status' => 'S', 'note' => 'Telepon ortu'])->assertOk();
        $this->assertSame('S', Attendance::where('student_id', $mine->students->first()->id)->value('status'));
        $this->post('/absensi/koreksi', ['student_id' => $mine->students->first()->id, 'date' => '2026-12-01', 'status' => 'S'])->assertSessionHasErrors('date');
    }

    public function test_headmaster_reads_but_cannot_change(): void
    {
        $class = $this->classWithStudents();
        $this->actingAs($this->user('kepsek'));
        $this->get('/absensi?kelas='.$class->id)->assertOk()->assertDontSee('data-autosave', false);
        $this->get('/rekap/ringkasan')->assertOk();
        $this->get('/izin/baru')->assertForbidden();
        $this->post('/absensi/koreksi', ['student_id' => $class->students->first()->id, 'date' => '2026-11-17', 'status' => 'A'])->assertForbidden();
    }
}
