<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Setting;
use App\Models\Student;
use App\Services\AttendanceService;
use App\Services\SchoolCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class KioskController extends Controller
{
    public function show(string $token)
    {
        $this->authorizeToken($token);
        $s = Setting::allValues();

        return view('kiosk.show', ['token' => $token, 'school' => $s['school_name'], 's' => $s, 'stats' => $this->counts()]);
    }

    public function scan(Request $request, string $token, AttendanceService $svc)
    {
        $this->authorizeToken($token);
        $code = (string) $request->input('code', '');

        return response()->json($svc->scan(mb_substr($code, 0, 64)) + ['stats' => $this->counts()]);
    }

    public function stats(string $token)
    {
        $this->authorizeToken($token);

        return response()->json($this->counts());
    }

    private function authorizeToken(string $token): void
    {
        abort_unless(hash_equals(Setting::kioskToken(), $token), 404);
    }

    private function counts(): array
    {
        $today = CarbonImmutable::today();
        $total = Student::where('status', 'aktif')->whereNotNull('class_id')->count();
        $in = Attendance::whereDate('date', $today->toDateString())->whereNotNull('check_in_at')->count();
        $late = Attendance::whereDate('date', $today->toDateString())->where('status', 'T')->count();

        return ['total' => $total, 'in' => $in, 'late' => $late, 'off' => SchoolCalendar::offReason($today)];
    }
}
