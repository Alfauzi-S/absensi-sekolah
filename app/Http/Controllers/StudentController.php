<?php

namespace App\Http\Controllers;

use App\Models\SchoolClass;
use App\Models\Setting;
use App\Models\Student;
use App\Services\RecapService;
use App\Services\StudentImporter;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StudentController extends Controller
{
    public function index(Request $request)
    {
        $students = Student::with('schoolClass')
            ->when($request->query('q'), fn ($q, $term) => $q->where(fn ($w) => $w->where('name', 'like', "%{$term}%")->orWhere('nis', 'like', "{$term}%")))
            ->when($request->query('kelas'), fn ($q, $c) => $q->where('class_id', $c))
            ->when($request->query('status', 'aktif'), fn ($q, $s) => $s === 'semua' ? $q : $q->where('status', $s))
            ->orderBy('name')->paginate(30)->withQueryString();

        return view('students.index', ['students' => $students, 'classes' => SchoolClass::orderBy('grade')->orderBy('name')->get()]);
    }

    public function show(Request $request, Student $student, RecapService $recap)
    {
        abort_unless($request->user()->canViewClass($student->class_id), 403);
        $month = $this->month($request->query('bulan'));
        $data = $recap->student($student, $month->startOfMonth(), $month->endOfMonth()->startOfDay());

        return view('students.show', $data + ['student' => $student->load('schoolClass'), 'month' => $month,
            'leaves' => $student->leaves()->latest('date_from')->limit(10)->get()]);
    }

    public function create()
    {
        return view('students.form', ['student' => new Student(['status' => 'aktif', 'gender' => 'L']), 'classes' => SchoolClass::orderBy('grade')->orderBy('name')->get()]);
    }

    public function store(Request $request)
    {
        $student = Student::create($this->validated($request));

        return redirect()->route('students.show', $student)->with('ok', 'Siswa '.$student->name.' ditambahkan.');
    }

    public function edit(Student $student)
    {
        return view('students.form', ['student' => $student, 'classes' => SchoolClass::orderBy('grade')->orderBy('name')->get()]);
    }

    public function update(Request $request, Student $student)
    {
        $student->update($this->validated($request, $student));

        return redirect()->route('students.show', $student)->with('ok', 'Data siswa disimpan.');
    }

    public function destroy(Student $student)
    {
        $student->delete();

        return redirect()->route('students.index')->with('ok', 'Siswa '.$student->name.' dihapus beserta riwayatnya.');
    }

    /** Ganti kode QR (kartu hilang): kartu lama langsung tidak berlaku. */
    public function reissue(Student $student)
    {
        $student->update(['qr_code' => Student::newQrCode()]);

        return redirect()->route('students.cards', ['siswa' => $student->id])->with('ok', 'Kartu baru dibuat. Kartu lama tidak berlaku lagi.');
    }

    /** Halaman cetak kartu QR (ukuran kartu ATM), untuk satu kelas atau satu siswa. */
    public function cards(Request $request)
    {
        $classes = SchoolClass::orderBy('grade')->orderBy('name')->get();
        $students = collect();
        if ($id = (int) $request->query('siswa')) {
            $students = Student::with('schoolClass')->whereKey($id)->get();
        } elseif ($classId = (int) $request->query('kelas')) {
            $students = Student::with('schoolClass')->where('class_id', $classId)->where('status', 'aktif')->orderBy('name')->get();
        }

        return view('students.cards', ['classes' => $classes, 'students' => $students, 'school' => Setting::get('school_name')]);
    }

    public function importForm()
    {
        return view('students.import');
    }

    public function import(Request $request, StudentImporter $importer)
    {
        $request->validate(['file' => 'required|file|max:5120']);
        $file = $request->file('file');
        try {
            $result = $importer->import($file->getRealPath(), $file->getClientOriginalName());
        } catch (\RuntimeException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        return back()->with('ok', "Impor selesai: {$result['created']} siswa baru, {$result['updated']} diperbarui.")
            ->with('importErrors', $result['errors']);
    }

    public function template()
    {
        return StudentImporter::template()->download('template-impor-siswa.xlsx');
    }

    private function validated(Request $request, ?Student $student = null): array
    {
        return $request->validate([
            'nis' => ['required', 'string', 'max:30', Rule::unique('students', 'nis')->ignore($student?->id)],
            'nisn' => 'nullable|string|max:30',
            'name' => 'required|string|max:120',
            'gender' => 'required|in:L,P',
            'class_id' => 'nullable|integer|exists:school_classes,id',
            'parent_phone' => 'nullable|string|max:20',
            'status' => ['required', Rule::in(array_keys(Student::STATUSES))],
        ]);
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
