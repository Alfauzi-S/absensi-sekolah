<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Services\AttendanceService;
use App\Services\SchoolCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AttendanceController extends Controller
{
    /** Daftar hadir satu kelas pada satu tanggal, bisa dikoreksi langsung. */
    public function index(Request $request)
    {
        $user = $request->user();
        $classes = SchoolClass::visibleTo($user)->get();
        $class = $classes->firstWhere('id', (int) $request->query('kelas')) ?? $classes->first();
        $date = $this->date($request->query('tanggal'));

        $rows = collect();
        if ($class) {
            $att = Attendance::where('class_id', $class->id)->whereDate('date', $date->toDateString())->get()->keyBy('student_id');
            $extra = Attendance::whereDate('date', $date->toDateString())->whereIn('student_id', $class->activeStudents()->pluck('id'))
                ->whereNotIn('id', $att->pluck('id'))->get()->keyBy('student_id');
            $att = $att->union($extra);
            $rows = $class->activeStudents()->get()->map(fn ($s) => ['student' => $s, 'att' => $att[$s->id] ?? null]);
        }

        $count = array_fill_keys(array_keys(Attendance::STATUSES), 0);
        foreach ($rows as $r) {
            if ($r['att']) {
                $count[$r['att']->status]++;
            }
        }

        return view('attendance.index', [
            'classes' => $classes,
            'class' => $class,
            'date' => $date,
            'rows' => $rows,
            'count' => $count,
            'offReason' => SchoolCalendar::offReason($date),
            'canEdit' => $class && $user->canEditClass($class->id) && $date->lessThanOrEqualTo(CarbonImmutable::today()),
        ]);
    }

    public function correct(Request $request, AttendanceService $svc)
    {
        $data = $request->validate([
            'student_id' => 'required|integer|exists:students,id',
            'date' => 'required|date|before_or_equal:today',
            'status' => ['required', Rule::in(array_keys(Attendance::STATUSES))],
            'note' => 'nullable|string|max:200',
        ]);
        $student = Student::findOrFail($data['student_id']);
        abort_unless($request->user()->canEditClass($student->class_id), 403);

        $svc->correct($student, CarbonImmutable::parse($data['date']), $data['status'], $data['note'] ?? null, $request->user()->id);

        if ($request->expectsJson()) {
            return response()->json(['ok' => true]);
        }

        return back()->with('ok', 'Status '.$student->name.' diubah menjadi '.Attendance::STATUSES[$data['status']].'.');
    }

    private function date(?string $value): CarbonImmutable
    {
        try {
            $d = $value ? CarbonImmutable::parse($value)->startOfDay() : CarbonImmutable::today();
        } catch (\Throwable) {
            $d = CarbonImmutable::today();
        }

        return $d;
    }
}
