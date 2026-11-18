<?php

namespace App\Http\Controllers;

use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ClassController extends Controller
{
    public function index()
    {
        $classes = SchoolClass::with('homeroomTeacher')
            ->withCount(['students as total' => fn ($q) => $q->where('status', 'aktif')])
            ->orderBy('grade')->orderBy('name')->get();

        return view('classes.index', ['classes' => $classes]);
    }

    public function create()
    {
        return view('classes.form', ['class' => new SchoolClass(['grade' => 10]), 'teachers' => $this->teachers(), 'students' => collect(), 'others' => collect()]);
    }

    public function store(Request $request)
    {
        $class = SchoolClass::create($this->validated($request));

        return redirect()->route('classes.edit', $class)->with('ok', 'Kelas '.$class->name.' dibuat.');
    }

    public function edit(SchoolClass $class)
    {
        return view('classes.form', [
            'class' => $class,
            'teachers' => $this->teachers(),
            'students' => $class->activeStudents()->get(),
            'others' => SchoolClass::where('id', '!=', $class->id)->orderBy('grade')->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, SchoolClass $class)
    {
        $class->update($this->validated($request, $class));

        return back()->with('ok', 'Kelas disimpan.');
    }

    public function destroy(SchoolClass $class)
    {
        if ($class->students()->where('status', 'aktif')->exists()) {
            return back()->withErrors(['class' => 'Kelas masih berisi siswa aktif. Pindahkan siswanya dulu.']);
        }
        $class->delete();

        return redirect()->route('classes.index')->with('ok', 'Kelas dihapus.');
    }

    /** Pindah/naik kelas: pindahkan siswa terpilih ke kelas lain, atau tandai lulus. */
    public function move(Request $request, SchoolClass $class)
    {
        $data = $request->validate([
            'students' => 'required|array|min:1',
            'students.*' => 'integer',
            'target' => 'required',
        ]);
        $query = Student::where('class_id', $class->id)->whereIn('id', $data['students']);
        if ($data['target'] === 'lulus') {
            $n = $query->update(['status' => 'lulus']);
            $msg = "{$n} siswa ditandai lulus.";
        } else {
            $target = SchoolClass::findOrFail((int) $data['target']);
            $n = $query->update(['class_id' => $target->id]);
            $msg = "{$n} siswa dipindah ke {$target->name}.";
        }

        return back()->with('ok', $msg);
    }

    private function teachers()
    {
        return User::where('is_active', true)->whereIn('role', ['guru', 'tu', 'kepsek', 'admin'])->orderBy('name')->get();
    }

    private function validated(Request $request, ?SchoolClass $class = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:30', Rule::unique('school_classes', 'name')->ignore($class?->id)],
            'grade' => 'required|integer|min:1|max:12',
            'homeroom_teacher_id' => 'nullable|integer|exists:users,id',
        ]);
    }
}
