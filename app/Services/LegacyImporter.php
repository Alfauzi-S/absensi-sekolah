<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\Student;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Memindahkan data dari aplikasi lama (PHP tanpa framework) yang tabelnya berada di database yang sama
 * tanpa awalan nama tabel. Tabel lama tidak diubah, jadi bisa kembali ke versi lama bila perlu.
 *
 * Yang dipindahkan: pengaturan sekolah dan jam, akun pengguna (password tetap), kelas tahun ajaran aktif,
 * siswa beserta kode kartu QR aktif (kartu yang sudah dicetak tetap berlaku), hari libur, surat izin,
 * dan kehadiran harian (sesi Masuk/Pulang).
 */
class LegacyImporter
{
    private Connection $old;

    public function __construct()
    {
        $default = config('database.default');
        config(['database.connections.legacy' => array_merge(config("database.connections.{$default}"), ['prefix' => ''])]);
        DB::purge('legacy');
        // Pakai koneksi PDO yang sama (database sama), hanya tanpa awalan nama tabel.
        $this->old = DB::connection('legacy');
        $this->old->setPdo(DB::connection()->getPdo())->setReadPdo(DB::connection()->getPdo());
    }

    public function available(): bool
    {
        $schema = $this->old->getSchemaBuilder();

        return $schema->hasTable('students') && $schema->hasColumn('students', 'full_name') && $schema->hasTable('cards');
    }

    public function alreadyImported(): bool
    {
        return DB::table('users')->exists();
    }

    /** @return array<string,int> jumlah baris per jenis data */
    public function run(?callable $log = null): array
    {
        $log ??= fn () => null;
        $stats = [];

        DB::transaction(function () use (&$stats, $log) {
            $stats['pengaturan'] = $this->settings();
            $stats['pengguna'] = $this->users();
            $log('Pengguna: '.$stats['pengguna']);
            $stats['kelas'] = $this->classes();
            $log('Kelas: '.$stats['kelas']);
            $stats['siswa'] = $this->students();
            $log('Siswa: '.$stats['siswa']);
            $stats['libur'] = $this->holidays();
            $stats['izin'] = $this->leaves();
            $log('Izin: '.$stats['izin']);
            $stats['kehadiran'] = $this->attendances();
            $log('Kehadiran: '.$stats['kehadiran']);
        });
        Setting::flush();

        return $stats;
    }

    private function settings(): int
    {
        if (! $this->old->getSchemaBuilder()->hasTable('settings')) {
            return 0;
        }
        $old = $this->old->table('settings')->pluck('svalue', 'skey')->all();
        $map = ['school_name' => 'school_name', 'school_address' => 'school_address', 'headmaster_name' => 'headmaster_name',
            'checkin_start' => 'checkin_start', 'checkout_start' => 'checkout_start', 'checkout_end' => 'checkout_end', 'weekend_days' => 'weekend_days'];
        $n = 0;
        foreach ($map as $from => $to) {
            if (isset($old[$from]) && $old[$from] !== '') {
                Setting::put($to, (string) $old[$from]);
                $n++;
            }
        }
        // Versi lama: terlambat bila lewat jam masuk + toleransi. Versi baru memakai satu jam batas.
        if (! empty($old['late_after'])) {
            [$h, $m] = array_map('intval', explode(':', $old['late_after'].':0'));
            $total = $h * 60 + $m + (int) ($old['late_tolerance_minutes'] ?? 0);
            Setting::put('late_after', sprintf('%02d:%02d', intdiv($total, 60) % 24, $total % 60));
            $n++;
        }

        return $n;
    }

    private function users(): int
    {
        $roles = ['super' => 'admin', 'tu' => 'tu', 'kepsek' => 'kepsek', 'guru' => 'guru'];
        $rows = $this->old->table('users')->orderBy('id')->get()->map(fn ($u) => [
            'id' => $u->id,
            'name' => $u->name,
            'username' => $u->username,
            'password' => $u->password_hash,
            'role' => $roles[$u->role] ?? 'guru',
            'nip' => $u->nip,
            'is_active' => $u->status === 'Aktif',
            'last_login_at' => $u->last_login_at,
            'created_at' => $u->created_at,
            'updated_at' => now(),
        ])->all();
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('users')->insert($chunk);
        }

        return count($rows);
    }

    private function activeYearId(): ?int
    {
        $year = $this->old->table('academic_years')->orderByDesc('is_active')->orderByDesc('start_date')->first();

        return $year?->id;
    }

    private function classes(): int
    {
        $yearId = $this->activeYearId();
        if (! $yearId) {
            return 0;
        }
        $userIds = DB::table('users')->pluck('id')->flip();
        $rows = $this->old->table('classes')->where('academic_year_id', $yearId)->orderBy('id')->get()->map(fn ($c) => [
            'id' => $c->id,
            'name' => $c->name,
            'grade' => $c->grade,
            'homeroom_teacher_id' => isset($userIds[$c->homeroom_teacher_id]) ? $c->homeroom_teacher_id : null,
            'created_at' => $c->created_at,
            'updated_at' => now(),
        ])->all();
        DB::table('school_classes')->insert($rows);

        return count($rows);
    }

    private function students(): int
    {
        $yearId = $this->activeYearId();
        $classOf = $yearId ? $this->old->table('class_members')->where('academic_year_id', $yearId)->pluck('class_id', 'student_id')->all() : [];
        $cards = $this->old->table('cards')->where('status', 'active')->orderBy('version')->pluck('code', 'student_id')->all();
        $classIds = DB::table('school_classes')->pluck('id')->flip();
        $status = ['Aktif' => 'aktif', 'Lulus' => 'lulus', 'Pindah' => 'pindah', 'Keluar' => 'keluar'];
        $n = 0;
        $used = [];
        $this->old->table('students')->orderBy('id')->chunk(1000, function ($chunk) use ($classOf, $cards, $classIds, $status, &$n, &$used) {
            $rows = [];
            foreach ($chunk as $s) {
                $code = $cards[$s->id] ?? null;
                if (! $code || isset($used[$code])) {
                    do {
                        $code = '';
                        for ($i = 0; $i < Student::QR_LENGTH; $i++) {
                            $code .= Student::QR_ALPHABET[random_int(0, strlen(Student::QR_ALPHABET) - 1)];
                        }
                    } while (isset($used[$code]) || in_array($code, $cards, true));
                }
                $used[$code] = true;
                $classId = $classOf[$s->id] ?? null;
                $rows[] = [
                    'id' => $s->id,
                    'nis' => $s->nis,
                    'nisn' => $s->nisn,
                    'name' => $s->full_name,
                    'gender' => $s->gender ?: 'L',
                    'class_id' => $classId !== null && isset($classIds[$classId]) ? $classId : null,
                    'qr_code' => $code,
                    'parent_phone' => $s->parent_phone,
                    'status' => $status[$s->status] ?? 'aktif',
                    'created_at' => $s->created_at,
                    'updated_at' => $s->updated_at,
                ];
            }
            DB::table('students')->insert($rows);
            $n += count($rows);
        });

        return $n;
    }

    private function holidays(): int
    {
        if (! $this->old->getSchemaBuilder()->hasTable('holidays')) {
            return 0;
        }
        $rows = $this->old->table('holidays')->get()->map(fn ($h) => [
            'date' => $h->holiday_date, 'name' => $h->name, 'created_at' => now(), 'updated_at' => now(),
        ])->all();
        DB::table('holidays')->insert($rows);

        return count($rows);
    }

    private function leaves(): int
    {
        if (! $this->old->getSchemaBuilder()->hasTable('leaves')) {
            return 0;
        }
        $students = DB::table('students')->pluck('id')->flip();
        $users = DB::table('users')->pluck('id')->flip();
        $rows = $this->old->table('leaves')->where('status', 'active')->orderBy('id')->get()
            ->filter(fn ($l) => isset($students[$l->student_id]))
            ->map(fn ($l) => [
                'id' => $l->id,
                'student_id' => $l->student_id,
                'kind' => $l->kind,
                'date_from' => $l->date_from,
                'date_to' => $l->date_to,
                'reason' => $l->reason,
                'attachment' => null,
                'created_by' => isset($users[$l->created_by]) ? $l->created_by : null,
                'created_at' => $l->created_at,
                'updated_at' => $l->created_at,
            ])->values()->all();
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('leaves')->insert($chunk);
        }

        return count($rows);
    }

    private function attendances(): int
    {
        $students = DB::table('students')->pluck('id')->flip();
        $classes = DB::table('school_classes')->pluck('id')->flip();
        $leaves = DB::table('leaves')->pluck('id')->flip();
        $users = DB::table('users')->pluck('id')->flip();
        $n = 0;

        foreach (['harian_masuk', 'harian_pulang'] as $kind) {
            $this->old->table('attendances as a')
                ->join('sessions as s', 's.id', '=', 'a.session_id')
                ->where('s.kind', $kind)->where('s.status', '!=', 'cancelled')
                ->select('a.id', 'a.student_id', 'a.class_id', 'a.status', 'a.scanned_at', 'a.late_minutes', 'a.method',
                    'a.note', 'a.leave_id', 'a.corrected', 'a.changed_by', 'a.created_at', 's.session_date')
                ->orderBy('a.id')
                ->chunk(2000, function ($chunk) use ($kind, $students, $classes, $leaves, $users, &$n) {
                    $rows = [];
                    foreach ($chunk as $a) {
                        if (! isset($students[$a->student_id])) {
                            continue;
                        }
                        $source = $a->corrected ? 'manual' : ($a->leave_id ? 'izin' : ($a->method === 'auto' ? 'auto' : 'scan'));
                        $rows[$a->student_id.'|'.$a->session_date] = [
                            'student_id' => $a->student_id,
                            'class_id' => isset($classes[$a->class_id]) ? $a->class_id : null,
                            'date' => substr((string) $a->session_date, 0, 10),
                            'status' => $a->status,
                            'check_in_at' => $kind === 'harian_masuk' ? $a->scanned_at : null,
                            'check_out_at' => $kind === 'harian_pulang' ? $a->scanned_at : null,
                            'late_minutes' => $kind === 'harian_masuk' ? (int) $a->late_minutes : 0,
                            'source' => $source,
                            'leave_id' => isset($leaves[$a->leave_id]) ? $a->leave_id : null,
                            'corrected' => (bool) $a->corrected,
                            'note' => $a->note,
                            'updated_by' => isset($users[$a->changed_by]) ? $a->changed_by : null,
                            'created_at' => $a->created_at,
                            'updated_at' => $a->created_at,
                        ];
                    }
                    if (! $rows) {
                        return;
                    }
                    if ($kind === 'harian_masuk') {
                        DB::table('attendances')->upsert(array_values($rows), ['student_id', 'date']);
                        $n += count($rows);
                    } else {
                        // Pulang: isi jam pulang pada baris Masuk yang sudah ada; buat baris baru bila tidak ada.
                        $before = DB::table('attendances')->count();
                        DB::table('attendances')->upsert(array_values($rows), ['student_id', 'date'], ['check_out_at']);
                        $n += DB::table('attendances')->count() - $before;
                    }
                });
        }

        return $n;
    }
}
