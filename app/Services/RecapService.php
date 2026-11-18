<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\SchoolClass;
use App\Models\Setting;
use App\Models\Student;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;

/** Rekap kehadiran: bulanan per kelas, riwayat per siswa, ringkasan per kelas. */
class RecapService
{
    public const CODES = ['H', 'T', 'S', 'I', 'D', 'A'];

    /**
     * Rekap bulanan satu kelas: satu baris per siswa, satu kolom per tanggal.
     *
     * @return array{class:SchoolClass, month:CarbonImmutable, days:array, off:array, rows:array, effective:int, daily:array}
     */
    public function monthly(SchoolClass $class, CarbonImmutable $month): array
    {
        $from = $month->startOfMonth();
        $to = $month->endOfMonth()->startOfDay();
        $days = [];
        foreach (CarbonPeriod::create($from, $to) as $d) {
            $days[] = CarbonImmutable::instance($d);
        }
        $off = SchoolCalendar::offDays($from, $to);

        // Siswa aktif, ditambah siswa nonaktif yang masih punya catatan di bulan ini.
        $students = $class->students()->where(function ($q) use ($from, $to) {
            $q->where('status', 'aktif')->orWhereHas('attendances', fn ($a) => $a->whereBetween('date', [$from->toDateString(), $to->toDateString()]));
        })->orderBy('name')->get();

        $att = Attendance::whereIn('student_id', $students->pluck('id'))
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get(['student_id', 'date', 'status']);
        $map = [];
        foreach ($att as $a) {
            $map[$a->student_id][substr($a->date, 0, 10)] = $a->status;
        }

        $effective = SchoolCalendar::schoolDayCount($from, $to);
        $daily = [];
        $rows = [];
        foreach ($students as $s) {
            $cells = [];
            $count = array_fill_keys(self::CODES, 0);
            foreach ($days as $d) {
                $key = $d->toDateString();
                $code = $map[$s->id][$key] ?? null;
                $cells[$key] = $code;
                if ($code && ! isset($off[$key])) {
                    $count[$code]++;
                    if (in_array($code, ['H', 'T'], true)) {
                        $daily[$key] = ($daily[$key] ?? 0) + 1;
                    }
                }
            }
            $rows[] = [
                'student' => $s,
                'cells' => $cells,
                'count' => $count,
                'percent' => $effective > 0 ? round(($count['H'] + $count['T']) / $effective * 100, 1) : null,
            ];
        }

        return compact('class', 'month', 'days', 'off', 'rows', 'effective', 'daily');
    }

    /** Riwayat satu siswa dalam rentang tanggal. */
    public function student(Student $student, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = Attendance::where('student_id', $student->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('date')->get();
        $count = array_fill_keys(self::CODES, 0);
        foreach ($rows as $r) {
            $count[$r->status]++;
        }
        $effective = SchoolCalendar::schoolDayCount($from, $to);

        return [
            'rows' => $rows,
            'count' => $count,
            'effective' => $effective,
            'late_minutes' => (int) $rows->sum('late_minutes'),
            'percent' => $effective > 0 ? round(($count['H'] + $count['T']) / $effective * 100, 1) : null,
        ];
    }

    /** Ringkasan per kelas dalam rentang tanggal (untuk kepala sekolah/TU). */
    public function summary(CarbonImmutable $from, CarbonImmutable $to, ?array $classIds = null): array
    {
        $classes = SchoolClass::with('homeroomTeacher')->withCount(['students as total' => fn ($q) => $q->where('status', 'aktif')])
            ->when($classIds !== null, fn ($q) => $q->whereIn('id', $classIds))
            ->orderBy('grade')->orderBy('name')->get();

        $counts = Attendance::query()
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('class_id, status, COUNT(*) as n')
            ->groupBy('class_id', 'status')->get()
            ->groupBy('class_id');

        $effective = SchoolCalendar::schoolDayCount($from, $to);
        $out = [];
        foreach ($classes as $c) {
            $count = array_fill_keys(self::CODES, 0);
            foreach ($counts[$c->id] ?? [] as $row) {
                $count[$row->status] = (int) $row->n;
            }
            $possible = $c->total * $effective;
            $out[] = [
                'class' => $c,
                'count' => $count,
                'percent' => $possible > 0 ? round(($count['H'] + $count['T']) / $possible * 100, 1) : null,
            ];
        }

        return ['rows' => $out, 'effective' => $effective];
    }

    /** File Excel rekap bulanan, siap cetak A4 landscape dengan tanda tangan. */
    public function monthlyXlsx(array $data): Xlsx
    {
        $s = Setting::allValues();
        $days = $data['days'];
        $nDays = count($days);
        $lastCol = 3 + $nDays + count(self::CODES);
        $rows = [];
        $rows[] = [['v' => mb_strtoupper($s['school_name']), 's' => 'title']];
        $rows[] = [['v' => 'Rekap Kehadiran Kelas '.$data['class']->name.' - '.$data['month']->translatedFormat('F Y'), 's' => 'b']];
        $rows[] = [['v' => 'Hari efektif: '.$data['effective'].'   |   Wali kelas: '.($data['class']->homeroomTeacher?->name ?? '-'), 's' => 'sub']];
        $rows[] = [];
        $head = [['v' => 'No', 's' => 'th'], ['v' => 'NIS', 's' => 'th'], ['v' => 'Nama', 's' => 'th']];
        foreach ($days as $d) {
            $head[] = ['v' => (int) $d->format('j'), 's' => 'th'];
        }
        foreach (self::CODES as $c) {
            $head[] = ['v' => $c, 's' => 'th'];
        }
        $head[] = ['v' => '% Hadir', 's' => 'th'];
        $rows[] = $head;
        foreach ($data['rows'] as $i => $r) {
            $line = [['v' => $i + 1, 's' => 'c'], ['v' => $r['student']->nis, 's' => 'l'], ['v' => $r['student']->name, 's' => 'l']];
            foreach ($days as $d) {
                $key = $d->toDateString();
                $line[] = isset($data['off'][$key]) ? ['v' => '', 's' => 'off'] : ['v' => $r['cells'][$key] ?? '', 's' => $r['cells'][$key] ?? 'c'];
            }
            foreach (self::CODES as $c) {
                $line[] = ['v' => $r['count'][$c], 's' => 'num'];
            }
            $line[] = ['v' => $r['percent'] === null ? '-' : $r['percent'].'%', 's' => 'num'];
            $rows[] = $line;
        }
        $rows[] = [];
        $rows[] = [['v' => 'Keterangan: H Hadir, T Terlambat, S Sakit, I Izin, D Dispensasi, A Alpa. Kolom abu-abu = libur.', 's' => 'sub']];
        $rows[] = [];
        $sign = array_fill(0, $lastCol, '');
        $sign[2] = 'Wali Kelas';
        $sign[$lastCol - 6] = 'Kepala Sekolah';
        $rows[] = $sign;
        $rows[] = [];
        $rows[] = [];
        $names = array_fill(0, $lastCol, '');
        $names[2] = ['v' => $data['class']->homeroomTeacher?->name ?? '..............................', 's' => 'b'];
        $names[$lastCol - 6] = ['v' => $s['headmaster_name'] ?: '..............................', 's' => 'b'];
        $rows[] = $names;
        if ($s['headmaster_nip']) {
            $nip = array_fill(0, $lastCol, '');
            $nip[2] = $data['class']->homeroomTeacher?->nip ? 'NIP '.$data['class']->homeroomTeacher->nip : '';
            $nip[$lastCol - 6] = 'NIP '.$s['headmaster_nip'];
            $rows[] = $nip;
        }

        $widths = array_merge([5, 12, 28], array_fill(0, $nDays, 3.6), array_fill(0, count(self::CODES), 4.5), [8]);
        $x = new Xlsx;
        $x->sheet($data['class']->name, $rows, ['widths' => $widths, 'freeze' => 'D6']);

        return $x;
    }
}
