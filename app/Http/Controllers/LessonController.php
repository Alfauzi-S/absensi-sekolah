<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Lesson;
use App\Models\Period;
use App\Models\SchoolClass;
use App\Models\Setting;
use App\Models\Subject;
use App\Services\LessonService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LessonController extends Controller
{
    public function index(Request $request)
    {
        abort_unless(Setting::lessonsEnabled(), 404);
        $user = $request->user();
        $date = $this->date($request->query('tanggal'));
        $visible = $user->visibleClassIds();
        $lessonIds = $user->lessonClassIds();

        $lessons = Lesson::with(['schoolClass', 'subject', 'teacher'])->withCount([
            'attendances as hadir' => fn ($q) => $q->whereIn('status', ['H', 'T']),
            'attendances as tidak' => fn ($q) => $q->whereNotIn('status', ['H', 'T']),
        ])
            ->whereDate('date', $date->toDateString())
            ->where(function ($q) use ($user, $visible) {
                $q->where('teacher_id', $user->id);
                if ($visible === null) {
                    $q->orWhereRaw('1 = 1');
                } elseif ($visible) {
                    $q->orWhereIn('class_id', $visible);
                }
            })
            ->orderBy('period_start')->get();

        return view('lessons.index', [
            'lessons' => $lessons,
            'date' => $date,
            'canCreate' => $lessonIds === null || count($lessonIds) > 0,
        ]);
    }

    public function create(Request $request)
    {
        $classes = $this->takeableClasses($request);
        abort_if($classes->isEmpty(), 403);

        return view('lessons.create', [
            'classes' => $classes,
            'subjects' => Subject::orderBy('name')->get(),
            'periods' => Period::orderBy('number')->get(),
            'current' => LessonService::currentPeriod(),
            'selectedClass' => (int) $request->query('kelas'),
        ]);
    }

    public function store(Request $request, LessonService $svc)
    {
        $data = $request->validate([
            'class_id' => 'required|integer|exists:school_classes,id',
            'subject_id' => 'nullable|integer|exists:subjects,id',
            'date' => 'required|date|before_or_equal:today',
            'period_start' => 'required|integer|min:1|max:20',
            'period_end' => 'required|integer|min:1|max:20|gte:period_start',
            'topic' => 'nullable|string|max:200',
        ], ['period_end.gte' => 'Jam selesai tidak boleh sebelum jam mulai.']);
        abort_unless($request->user()->canTakeLesson((int) $data['class_id']), 403);

        $existing = Lesson::where('class_id', $data['class_id'])->whereDate('date', $data['date'])
            ->where('period_start', '<=', $data['period_end'])->where('period_end', '>=', $data['period_start'])->first();
        if ($existing) {
            return redirect()->route('lessons.show', $existing)->with('ok', 'Sesi untuk jam ini sudah ada; Anda diarahkan ke sesi tersebut.');
        }
        $lesson = $svc->create($data, $request->user()->id);

        return redirect()->route('lessons.show', $lesson);
    }

    public function show(Request $request, Lesson $lesson)
    {
        $this->authorizeView($request, $lesson);
        $marks = $lesson->attendances()->with('student')->get()->sortBy(fn ($m) => $m->student->name)->values();
        $count = array_fill_keys(array_keys(Attendance::STATUSES), 0);
        foreach ($marks as $m) {
            $count[$m->status]++;
        }

        return view('lessons.show', [
            'lesson' => $lesson->load('schoolClass', 'subject', 'teacher'),
            'marks' => $marks,
            'count' => $count,
            'canEdit' => $this->canEdit($request, $lesson),
            'qr' => Setting::get('lesson_mode') === 'qr',
        ]);
    }

    public function mark(Request $request, Lesson $lesson, LessonService $svc)
    {
        abort_unless($this->canEdit($request, $lesson), 403);
        $data = $request->validate([
            'student_id' => 'required|integer',
            'status' => ['required', Rule::in(array_keys(Attendance::STATUSES))],
            'note' => 'nullable|string|max:200',
        ]);
        $svc->mark($lesson, (int) $data['student_id'], $data['status'], $data['note'] ?? null);

        return $request->expectsJson() ? response()->json(['ok' => true]) : back();
    }

    public function scan(Request $request, Lesson $lesson, LessonService $svc)
    {
        abort_unless($this->canEdit($request, $lesson) && Setting::get('lesson_mode') === 'qr', 403);

        return response()->json($svc->scan($lesson, mb_substr((string) $request->input('code'), 0, 64)));
    }

    public function destroy(Request $request, Lesson $lesson)
    {
        abort_unless($this->canEdit($request, $lesson), 403);
        $lesson->delete();

        return redirect()->route('lessons.index', ['tanggal' => $lesson->date])->with('ok', 'Sesi pelajaran dihapus.');
    }

    public function recap(Request $request, LessonService $svc)
    {
        $classes = SchoolClass::visibleTo($request->user())->get();
        $class = $classes->firstWhere('id', (int) $request->query('kelas')) ?? $classes->first();
        $month = $this->month($request->query('bulan'));

        return view('lessons.recap', ['classes' => $classes, 'class' => $class, 'month' => $month,
            'data' => $class ? $svc->monthly($class, $month) : null]);
    }

    public function download(Request $request, LessonService $svc)
    {
        $class = SchoolClass::findOrFail((int) $request->query('kelas'));
        abort_unless($request->user()->canViewClass($class->id), 403);
        $month = $this->month($request->query('bulan'));

        return $svc->monthlyXlsx($svc->monthly($class, $month), Setting::get('school_name'))
            ->download('rekap-per-jam-'.str($class->name)->slug().'-'.$month->format('Y-m').'.xlsx');
    }

    private function takeableClasses(Request $request)
    {
        $ids = $request->user()->lessonClassIds();
        abort_unless(Setting::lessonsEnabled(), 404);

        return SchoolClass::query()->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->orderBy('grade')->orderBy('name')->get();
    }

    private function authorizeView(Request $request, Lesson $lesson): void
    {
        $u = $request->user();
        abort_unless($lesson->teacher_id === $u->id || $u->canViewClass($lesson->class_id), 403);
    }

    private function canEdit(Request $request, Lesson $lesson): bool
    {
        $u = $request->user();

        return $u->canManageData() || ($lesson->teacher_id === $u->id && Setting::lessonsEnabled());
    }

    private function date(?string $value): CarbonImmutable
    {
        try {
            return $value ? CarbonImmutable::parse($value)->startOfDay() : CarbonImmutable::today();
        } catch (\Throwable) {
            return CarbonImmutable::today();
        }
    }

    private function month(?string $value): CarbonImmutable
    {
        try {
            return $value ? CarbonImmutable::createFromFormat('Y-m', $value)->startOfMonth() : CarbonImmutable::today()->startOfMonth();
        } catch (\Throwable) {
            return CarbonImmutable::today()->startOfMonth();
        }
    }
}
