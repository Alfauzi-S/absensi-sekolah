<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Lesson;
use App\Models\LessonAttendance;
use App\Models\Period;
use App\Models\SchoolClass;
use App\Models\Student;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Absensi per jam pelajaran. Guru membuka sesi untuk satu kelas; daftar siswa langsung terisi Hadir,
 * kecuali siswa yang pada absensi harian berstatus Sakit/Izin/Dispensasi/Alpa (statusnya ikut).
 * Guru lalu mengubah yang tidak hadir, atau memindai kartu QR siswa bila mode QR aktif.
 */
class LessonService
{
    public function create(array $data, int $teacherId): Lesson
    {
        return DB::transaction(function () use ($data, $teacherId) {
            $lesson = Lesson::create($data + ['teacher_id' => $teacherId]);
            $class = SchoolClass::findOrFail($lesson->class_id);
            $daily = Attendance::whereDate('date', $lesson->date)
                ->whereIn('student_id', $class->activeStudents()->pluck('id'))
                ->whereIn('status', ['S', 'I', 'D', 'A'])->pluck('status', 'student_id');
            $now = now();
            $rows = $class->activeStudents()->pluck('id')->map(fn ($id) => [
                'lesson_id' => $lesson->id,
                'student_id' => $id,
                'status' => $daily[$id] ?? 'H',
                'note' => isset($daily[$id]) ? 'Dari absensi harian' : null,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();
            foreach (array_chunk($rows, 200) as $chunk) {
                LessonAttendance::insert($chunk);
            }

            return $lesson;
        });
    }

    public function mark(Lesson $lesson, int $studentId, string $status, ?string $note): LessonAttendance
    {
        return LessonAttendance::updateOrCreate(
            ['lesson_id' => $lesson->id, 'student_id' => $studentId],
            ['status' => $status, 'note' => $note],
        );
    }

    /** Scan kartu di sesi pelajaran: tandai Hadir. */
    public function scan(Lesson $lesson, string $raw): array
    {
        $student = Student::where('qr_code', Student::normalizeCode($raw))->first();
        if (! $student) {
            return ['ok' => false, 'tone' => 'error', 'title' => 'Kartu tidak dikenal', 'message' => 'Kartu tidak terdaftar atau sudah diganti.'];
        }
        if ($student->class_id !== $lesson->class_id) {
            return ['ok' => false, 'tone' => 'error', 'title' => $student->name, 'message' => 'Bukan siswa kelas ini.'];
        }
        $row = LessonAttendance::firstOrNew(['lesson_id' => $lesson->id, 'student_id' => $student->id]);
        $row->fill(['status' => 'H', 'scanned_at' => now(), 'note' => null])->save();

        return ['ok' => true, 'tone' => 'success', 'title' => $student->name, 'message' => 'Hadir', 'student_id' => $student->id];
    }

    /** Tebakan jam pelajaran yang sedang berjalan, untuk mengisi formulir. */
    public static function currentPeriod(): ?int
    {
        $now = CarbonImmutable::now()->format('H:i');

        return Period::where('start_time', '<=', $now)->where('end_time', '>', $now)->value('number')
            ?? Period::where('start_time', '>', $now)->orderBy('number')->value('number');
    }

    /** Rekap per jam satu kelas dalam satu bulan: per siswa, jumlah per status dan per mapel. */
    public function monthly(SchoolClass $class, CarbonImmutable $month): array
    {
        $lessons = Lesson::with('subject', 'teacher')->where('class_id', $class->id)
            ->whereBetween('date', [$month->startOfMonth()->toDateString(), $month->endOfMonth()->toDateString()])
            ->orderBy('date')->orderBy('period_start')->get();
        $marks = LessonAttendance::whereIn('lesson_id', $lessons->pluck('id'))->get()->groupBy('student_id');
        $students = $class->students()->where(fn ($q) => $q->where('status', 'aktif')->orWhereIn('id', $marks->keys()))->orderBy('name')->get();
        $rows = [];
        foreach ($students as $s) {
            $byLesson = ($marks[$s->id] ?? collect())->keyBy('lesson_id');
            $count = array_fill_keys(RecapService::CODES, 0);
            foreach ($byLesson as $m) {
                $count[$m->status]++;
            }
            $total = array_sum($count);
            $rows[] = ['student' => $s, 'cells' => $byLesson->map->status->all(), 'count' => $count,
                'percent' => $total ? round(($count['H'] + $count['T']) / $total * 100, 1) : null];
        }

        return compact('class', 'month', 'lessons', 'rows');
    }

    public function monthlyXlsx(array $data, string $school): Xlsx
    {
        $rows = [[['v' => mb_strtoupper($school), 's' => 'title']],
            [['v' => 'Rekap Absensi per Jam Pelajaran Kelas '.$data['class']->name.' - '.$data['month']->translatedFormat('F Y'), 's' => 'b']], []];
        $head = [['v' => 'No', 's' => 'th'], ['v' => 'NIS', 's' => 'th'], ['v' => 'Nama', 's' => 'th']];
        foreach ($data['lessons'] as $l) {
            $head[] = ['v' => CarbonImmutable::parse($l->date)->format('j/n')."\n".($l->subject?->name ?? '-')."\nJ".$l->period_start, 's' => 'th'];
        }
        foreach (RecapService::CODES as $c) {
            $head[] = ['v' => $c, 's' => 'th'];
        }
        $head[] = ['v' => '% Hadir', 's' => 'th'];
        $rows[] = $head;
        foreach ($data['rows'] as $i => $r) {
            $line = [['v' => $i + 1, 's' => 'c'], ['v' => $r['student']->nis, 's' => 'l'], ['v' => $r['student']->name, 's' => 'l']];
            foreach ($data['lessons'] as $l) {
                $code = $r['cells'][$l->id] ?? '';
                $line[] = ['v' => $code, 's' => $code ?: 'c'];
            }
            foreach (RecapService::CODES as $c) {
                $line[] = ['v' => $r['count'][$c], 's' => 'num'];
            }
            $line[] = ['v' => $r['percent'] === null ? '-' : $r['percent'].'%', 's' => 'num'];
            $rows[] = $line;
        }
        $x = new Xlsx;
        $x->sheet($data['class']->name, $rows, ['widths' => array_merge([5, 12, 28], array_fill(0, count($data['lessons']), 9), array_fill(0, 6, 4.5), [8]), 'freeze' => 'D5']);

        return $x;
    }
}
