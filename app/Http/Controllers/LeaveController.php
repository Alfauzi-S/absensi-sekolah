<?php

namespace App\Http\Controllers;

use App\Models\Leave;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Services\AttendanceService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class LeaveController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $classIds = $user->visibleClassIds();
        $classes = SchoolClass::visibleTo($user)->get();
        $classId = (int) $request->query('kelas');
        $leaves = Leave::with(['student.schoolClass', 'creator'])
            ->whereHas('student', function ($q) use ($classIds, $classId) {
                $q->when($classIds !== null, fn ($q) => $q->whereIn('class_id', $classIds))
                    ->when($classId > 0, fn ($q) => $q->where('class_id', $classId));
            })
            ->when($request->query('q'), fn ($q, $term) => $q->whereHas('student', fn ($s) => $s->where('name', 'like', "%{$term}%")->orWhere('nis', $term)))
            ->orderByDesc('date_from')->orderByDesc('id')->paginate(25)->withQueryString();

        return view('leaves.index', [
            'leaves' => $leaves,
            'classes' => $classes,
            'canCreate' => $user->role !== 'kepsek',
        ]);
    }

    public function create(Request $request)
    {
        abort_if($request->user()->role === 'kepsek', 403);
        $classIds = $request->user()->visibleClassIds();
        $students = Student::with('schoolClass')->where('status', 'aktif')
            ->when($classIds !== null, fn ($q) => $q->whereIn('class_id', $classIds))
            ->orderBy('name')->get();

        return view('leaves.create', ['students' => $students, 'selected' => (int) $request->query('siswa')]);
    }

    public function store(Request $request, AttendanceService $svc)
    {
        $data = $request->validate([
            'student_id' => 'required|integer|exists:students,id',
            'kind' => ['required', Rule::in(array_keys(Leave::KINDS))],
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
            'reason' => 'required|string|max:255',
            'attachment' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:3072',
        ]);
        $student = Student::findOrFail($data['student_id']);
        abort_unless($request->user()->canEditClass($student->class_id), 403);
        if (CarbonImmutable::parse($data['date_from'])->diffInDays(CarbonImmutable::parse($data['date_to'])) > 60) {
            return back()->withInput()->withErrors(['date_to' => 'Rentang izin paling lama 60 hari.']);
        }

        $path = $request->file('attachment')?->store('lampiran-izin');
        $leave = Leave::create([
            'student_id' => $student->id,
            'kind' => $data['kind'],
            'date_from' => $data['date_from'],
            'date_to' => $data['date_to'],
            'reason' => $data['reason'],
            'attachment' => $path,
            'created_by' => $request->user()->id,
        ]);
        $svc->applyLeave($leave, $request->user()->id);

        return redirect()->route('leaves.index')->with('ok', $leave->kindLabel().' untuk '.$student->name.' dicatat.');
    }

    public function destroy(Request $request, Leave $leave, AttendanceService $svc)
    {
        abort_unless($request->user()->canEditClass($leave->student->class_id), 403);
        if ($leave->attachment) {
            Storage::delete($leave->attachment);
        }
        $svc->removeLeave($leave, $request->user()->id);

        return back()->with('ok', 'Surat izin dihapus; status kehadiran dikembalikan.');
    }

    public function attachment(Request $request, Leave $leave)
    {
        abort_unless($leave->attachment && $request->user()->canViewClass($leave->student->class_id), 404);

        return Storage::response($leave->attachment);
    }
}
