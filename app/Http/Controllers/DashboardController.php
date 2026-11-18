<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Services\SchoolCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $today = CarbonImmutable::today();
        $classIds = $user->visibleClassIds();

        $classes = SchoolClass::visibleTo($user)->with('homeroomTeacher')
            ->withCount(['students as total' => fn ($q) => $q->where('status', 'aktif')])->get();

        $counts = Attendance::whereDate('date', $today->toDateString())
            ->when($classIds !== null, fn ($q) => $q->whereIn('class_id', $classIds))
            ->selectRaw('class_id, status, COUNT(*) as n')->groupBy('class_id', 'status')->get();

        $byClass = [];
        $totals = ['H' => 0, 'T' => 0, 'S' => 0, 'I' => 0, 'D' => 0, 'A' => 0];
        foreach ($counts as $c) {
            $byClass[$c->class_id][$c->status] = (int) $c->n;
            $totals[$c->status] += (int) $c->n;
        }
        $students = (int) $classes->sum('total');
        $recorded = array_sum($totals);

        // Tren 10 hari sekolah terakhir: % hadir (H+T) dari siswa aktif.
        $trend = [];
        $d = $today;
        $guard = 0;
        while (count($trend) < 10 && $guard++ < 40) {
            if (SchoolCalendar::isSchoolDay($d)) {
                $trend[$d->toDateString()] = null;
            }
            $d = $d->subDay();
        }
        $trendCounts = Attendance::whereIn('date', array_keys($trend))
            ->when($classIds !== null, fn ($q) => $q->whereIn('class_id', $classIds))
            ->whereIn('status', ['H', 'T'])
            ->selectRaw('date, COUNT(*) as n')->groupBy('date')->pluck('n', 'date');
        foreach ($trendCounts as $date => $n) {
            $trend[substr($date, 0, 10)] = $students > 0 ? round($n / $students * 100) : 0;
        }
        $trend = array_reverse($trend, true);

        // Siswa dengan alpa terbanyak 30 hari terakhir.
        $frequentAbsent = Student::with('schoolClass')
            ->when($classIds !== null, fn ($q) => $q->whereIn('class_id', $classIds))
            ->withCount(['attendances as alpa' => fn ($q) => $q->where('status', 'A')->where('date', '>=', $today->subDays(30)->toDateString())])
            ->where('status', 'aktif')->orderByDesc('alpa')->limit(5)->get()->filter(fn ($s) => $s->alpa >= 2);

        return view('dashboard.index', [
            'today' => $today,
            'offReason' => SchoolCalendar::offReason($today),
            'classes' => $classes,
            'byClass' => $byClass,
            'totals' => $totals,
            'students' => $students,
            'notYet' => max(0, $students - $recorded),
            'trend' => $trend,
            'frequentAbsent' => $frequentAbsent,
            'recent' => $this->recent($classIds),
        ]);
    }

    /** Daftar scan terbaru, dipanggil ulang tiap 15 detik dari dashboard. */
    public function feed(Request $request)
    {
        return view('dashboard._recent', ['recent' => $this->recent($request->user()->visibleClassIds())]);
    }

    private function recent(?array $classIds)
    {
        return Attendance::with('student.schoolClass')
            ->whereDate('date', CarbonImmutable::today()->toDateString())
            ->whereNotNull('check_in_at')
            ->when($classIds !== null, fn ($q) => $q->whereIn('class_id', $classIds))
            ->orderByDesc('updated_at')->limit(8)->get();
    }
}
