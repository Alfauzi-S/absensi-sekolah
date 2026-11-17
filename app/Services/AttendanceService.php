<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Leave;
use App\Models\Student;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;

/**
 * Aturan absensi harian (satu baris per siswa per hari).
 *
 * Jam (Pengaturan): scan masuk mulai checkin_start; lewat late_after = Terlambat; scan masuk masih diterima
 * sampai checkout_start, setelah itu scan dihitung pulang sampai checkout_end. Pada checkout_start siswa yang
 * belum tercatat diberi Alpa (atau Izin/Sakit/Dispensasi bila ada surat).
 *
 * Prioritas status: koreksi manual > Izin/Sakit/Dispensasi > scan (Hadir/Terlambat) > Alpa otomatis.
 * Jam scan selalu disimpan walau statusnya kalah prioritas.
 */
class AttendanceService
{
    /**
     * Proses satu scan kartu dari kiosk.
     *
     * @return array{ok:bool, tone:string, title:string, message:string, student?:array}
     */
    public function scan(string $raw, ?CarbonInterface $now = null): array
    {
        $now = CarbonImmutable::instance($now ?? now());
        $code = Student::normalizeCode($raw);
        $student = $code === '' ? null : Student::with('schoolClass')->where('qr_code', $code)->first();

        if (! $student) {
            return $this->reply(false, 'error', 'Kartu tidak dikenal', 'Kartu ini tidak terdaftar atau sudah diganti. Hubungi TU.');
        }
        if ($student->status !== 'aktif') {
            return $this->reply(false, 'error', 'Siswa tidak aktif', $student->name.' berstatus '.Student::STATUSES[$student->status].'.', $student);
        }
        if ($reason = SchoolCalendar::offReason($now)) {
            return $this->reply(false, 'warn', 'Hari libur', 'Hari ini libur ('.$reason.'). Scan tidak dicatat.', $student);
        }

        $checkinStart = SchoolCalendar::at($now, 'checkin_start');
        $lateAfter = SchoolCalendar::at($now, 'late_after');
        $checkoutStart = SchoolCalendar::at($now, 'checkout_start');
        $checkoutEnd = SchoolCalendar::at($now, 'checkout_end');

        if ($now->lessThan($checkinStart)) {
            return $this->reply(false, 'warn', 'Belum waktunya', 'Scan masuk dibuka pukul '.$checkinStart->format('H:i').'.', $student);
        }
        if ($now->greaterThan($checkoutEnd)) {
            return $this->reply(false, 'warn', 'Scan sudah ditutup', 'Scan pulang berakhir pukul '.$checkoutEnd->format('H:i').'.', $student);
        }

        return DB::transaction(function () use ($student, $now, $lateAfter, $checkoutStart) {
            $row = Attendance::where('student_id', $student->id)->whereDate('date', $now->toDateString())->lockForUpdate()->first();
            $isCheckout = $now->greaterThanOrEqualTo($checkoutStart);

            if ($isCheckout) {
                if ($row?->check_out_at) {
                    return $this->reply(true, 'info', 'Sudah scan pulang', 'Tercatat pulang pukul '.$row->check_out_at->format('H:i').'.', $student);
                }
                $row ??= new Attendance(['student_id' => $student->id, 'date' => $now->toDateString(), 'status' => 'A', 'source' => 'auto']);
                $row->class_id ??= $student->class_id;
                $row->check_out_at = $now;
                if (! $row->check_in_at && ! $row->corrected && ! $row->leave_id) {
                    // Datang tanpa scan masuk: dianggap terlambat agar tidak tercatat Alpa.
                    $row->fill(['status' => 'T', 'source' => 'scan', 'note' => 'Tidak scan masuk']);
                }
                $row->save();

                return $this->reply(true, 'success', 'Hati-hati di jalan', 'Pulang tercatat pukul '.$now->format('H:i').'.', $student);
            }

            if ($row?->check_in_at) {
                return $this->reply(true, 'info', 'Sudah tercatat', 'Sudah scan masuk pukul '.$row->check_in_at->format('H:i').'.', $student);
            }

            $late = $now->greaterThan($lateAfter) ? (int) floor($lateAfter->diffInMinutes($now)) : 0;
            $row ??= new Attendance(['student_id' => $student->id, 'date' => $now->toDateString()]);
            $row->class_id ??= $student->class_id;
            $row->check_in_at = $now;
            $row->late_minutes = $late;

            if ($row->corrected || $row->leave_id) {
                $row->save();

                return $this->reply(true, 'info', 'Tercatat', 'Jam scan disimpan; status tetap '.$row->statusLabel().'.', $student);
            }

            $row->status = $late > 0 ? 'T' : 'H';
            $row->source = 'scan';
            $row->save();

            return $late > 0
                ? $this->reply(true, 'warn', 'Terlambat '.$late.' menit', 'Masuk pukul '.$now->format('H:i').'.', $student)
                : $this->reply(true, 'success', 'Selamat datang', 'Hadir pukul '.$now->format('H:i').'.', $student);
        });
    }

    /**
     * Beri Alpa (atau status surat) kepada siswa aktif yang belum tercatat pada hari sekolah ini.
     * Hanya berjalan setelah jam scan masuk berakhir. Aman dijalankan berulang.
     */
    public function markAbsent(CarbonInterface $date, ?CarbonInterface $now = null): int
    {
        $now = CarbonImmutable::instance($now ?? now());
        $date = CarbonImmutable::parse($date->toDateString());
        if (! SchoolCalendar::isSchoolDay($date) || $now->lessThan(SchoolCalendar::at($date, 'checkout_start'))) {
            return 0;
        }

        $missing = Student::where('status', 'aktif')->whereNotNull('class_id')
            ->whereDoesntHave('attendances', fn ($q) => $q->whereDate('date', $date->toDateString()))
            ->get(['id', 'class_id']);

        $n = 0;
        foreach ($missing as $s) {
            $leave = $this->leaveOn($s->id, $date);
            Attendance::firstOrCreate(
                ['student_id' => $s->id, 'date' => $date->toDateString()],
                [
                    'class_id' => $s->class_id,
                    'status' => $leave?->kind ?? 'A',
                    'source' => $leave ? 'izin' : 'auto',
                    'leave_id' => $leave?->id,
                ],
            );
            $n++;
        }

        return $n;
    }

    /** Terapkan surat izin ke setiap hari sekolah dalam rentangnya (yang belum dikoreksi manual). */
    public function applyLeave(Leave $leave, ?int $actorId = null): int
    {
        $student = $leave->student;
        $n = 0;
        foreach (CarbonPeriod::create($leave->date_from, $leave->date_to) as $d) {
            if (! SchoolCalendar::isSchoolDay($d)) {
                continue;
            }
            $row = Attendance::firstOrNew(['student_id' => $student->id, 'date' => $d->toDateString()]);
            if ($row->corrected) {
                continue;
            }
            $row->fill([
                'class_id' => $row->class_id ?? $student->class_id,
                'status' => $leave->kind,
                'source' => 'izin',
                'leave_id' => $leave->id,
                'note' => $leave->reason,
                'updated_by' => $actorId,
            ])->save();
            $n++;
        }

        return $n;
    }

    /** Hapus surat izin: status kembali ke hasil scan, Alpa (hari yang sudah lewat), atau kosong (hari mendatang). */
    public function removeLeave(Leave $leave, ?int $actorId = null): void
    {
        DB::transaction(function () use ($leave, $actorId) {
            $rows = Attendance::where('leave_id', $leave->id)->where('corrected', false)->get();
            foreach ($rows as $row) {
                $date = CarbonImmutable::parse($row->date);
                $row->leave_id = null;
                $row->note = null;
                $row->updated_by = $actorId;
                if ($row->check_in_at) {
                    $row->status = $row->late_minutes > 0 ? 'T' : 'H';
                    $row->source = 'scan';
                    $row->save();
                } elseif (now()->greaterThanOrEqualTo(SchoolCalendar::at($date, 'checkout_start'))) {
                    $row->status = $row->check_out_at ? 'T' : 'A';
                    $row->source = 'auto';
                    $row->save();
                } else {
                    $row->delete();
                }
            }
            $leave->delete();
        });
    }

    /** Koreksi manual oleh guru/wali kelas/TU. Mengalahkan surat dan scan. */
    public function correct(Student $student, CarbonInterface $date, string $status, ?string $note, ?int $actorId): Attendance
    {
        $row = Attendance::firstOrNew(['student_id' => $student->id, 'date' => $date->toDateString()]);
        $row->fill([
            'class_id' => $row->class_id ?? $student->class_id,
            'status' => $status,
            'source' => 'manual',
            'corrected' => true,
            'note' => $note,
            'updated_by' => $actorId,
        ])->save();

        return $row;
    }

    private function leaveOn(int $studentId, CarbonInterface $date): ?Leave
    {
        return Leave::where('student_id', $studentId)
            ->whereDate('date_from', '<=', $date->toDateString())
            ->whereDate('date_to', '>=', $date->toDateString())
            ->latest('id')->first();
    }

    private function reply(bool $ok, string $tone, string $title, string $message, ?Student $student = null): array
    {
        $out = compact('ok', 'tone', 'title', 'message');
        if ($student) {
            $out['student'] = [
                'name' => $student->name,
                'nis' => $student->nis,
                'class' => $student->schoolClass?->name,
                'initials' => $student->initials(),
            ];
        }

        return $out;
    }
}
