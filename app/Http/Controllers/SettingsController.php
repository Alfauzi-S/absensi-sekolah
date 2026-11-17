<?php

namespace App\Http\Controllers;

use App\Models\Holiday;
use App\Models\Period;
use App\Models\Subject;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SettingsController extends Controller
{
    public function edit()
    {
        return view('settings.edit', [
            's' => Setting::allValues(),
            'kioskUrl' => route('kiosk', Setting::kioskToken()),
            'subjects' => Subject::orderBy('name')->get(),
            'periods' => Period::orderBy('number')->get(),
            'holidays' => Holiday::where('date', '>=', now()->subMonths(1)->toDateString())->orderBy('date')->get(),
        ]);
    }

    public function update(Request $request)
    {
        $time = 'required|date_format:H:i';
        $data = $request->validate([
            'school_name' => 'required|string|max:120',
            'school_address' => 'nullable|string|max:255',
            'headmaster_name' => 'nullable|string|max:120',
            'headmaster_nip' => 'nullable|string|max:30',
            'checkin_start' => $time,
            'late_after' => $time.'|after:checkin_start',
            'checkout_start' => $time.'|after:late_after',
            'checkout_end' => $time.'|after:checkout_start',
            'weekend_days' => 'nullable|array',
            'weekend_days.*' => 'integer|between:1,7',
            'lesson_mode' => 'required|in:'.implode(',', array_keys(Setting::LESSON_MODES)),
            'lesson_access' => 'required|in:'.implode(',', array_keys(Setting::LESSON_ACCESS)),
        ], [
            'late_after.after' => 'Batas terlambat harus setelah jam mulai scan masuk.',
            'checkout_start.after' => 'Jam pulang harus setelah batas terlambat.',
            'checkout_end.after' => 'Akhir scan pulang harus setelah jam pulang.',
        ]);
        $data['weekend_days'] = implode(',', $data['weekend_days'] ?? []);
        foreach ($data as $key => $value) {
            Setting::put($key, (string) $value);
        }

        return back()->with('ok', 'Pengaturan disimpan.');
    }

    public function addHoliday(Request $request)
    {
        $data = $request->validate(['date' => 'required|date|unique:holidays,date', 'name' => 'required|string|max:120'],
            ['date.unique' => 'Tanggal itu sudah tercatat libur.']);
        Holiday::create($data);

        return back()->with('ok', 'Hari libur ditambahkan.');
    }

    public function removeHoliday(Holiday $holiday)
    {
        $holiday->delete();

        return back()->with('ok', 'Hari libur dihapus.');
    }

    public function addSubject(Request $request)
    {
        $data = $request->validate(['subject' => 'required|string|max:80|unique:subjects,name'], ['subject.unique' => 'Mapel itu sudah ada.']);
        Subject::create(['name' => $data['subject']]);

        return back()->with('ok', 'Mapel ditambahkan.');
    }

    public function removeSubject(Subject $subject)
    {
        $subject->delete();

        return back()->with('ok', 'Mapel dihapus.');
    }

    public function addPeriod(Request $request)
    {
        $data = $request->validate(['start_time' => 'required|date_format:H:i', 'end_time' => 'required|date_format:H:i|after:start_time'],
            ['end_time.after' => 'Jam selesai harus setelah jam mulai.']);
        Period::create($data + ['number' => (int) Period::max('number') + 1]);

        return back()->with('ok', 'Jam pelajaran ditambahkan.');
    }

    public function removePeriod(Period $period)
    {
        $period->delete();
        // Nomori ulang agar tetap berurutan 1, 2, 3, ...
        foreach (Period::orderBy('start_time')->get() as $i => $p) {
            $p->update(['number' => 100 + $i]);
        }
        foreach (Period::orderBy('number')->get() as $i => $p) {
            $p->update(['number' => $i + 1]);
        }

        return back()->with('ok', 'Jam pelajaran dihapus.');
    }

    /** Buat tautan kiosk baru; tautan lama langsung tidak bisa dipakai. */
    public function resetKiosk()
    {
        Setting::put('kiosk_token', Str::random(32));

        return back()->with('ok', 'Tautan kiosk baru dibuat. Buka tautan baru di tablet scanner.');
    }
}
