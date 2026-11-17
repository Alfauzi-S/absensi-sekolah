<?php

namespace App\Http\Controllers;

use App\Models\SchoolClass;
use App\Services\RecapService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class RecapController extends Controller
{
    public function monthly(Request $request, RecapService $recap)
    {
        $classes = SchoolClass::visibleTo($request->user())->get();
        $class = $classes->firstWhere('id', (int) $request->query('kelas')) ?? $classes->first();
        $month = $this->month($request->query('bulan'));

        return view('recap.monthly', [
            'classes' => $classes,
            'class' => $class,
            'month' => $month,
            'data' => $class ? $recap->monthly($class->load('homeroomTeacher'), $month) : null,
        ]);
    }

    public function download(Request $request, RecapService $recap)
    {
        $class = SchoolClass::with('homeroomTeacher')->findOrFail((int) $request->query('kelas'));
        abort_unless($request->user()->canViewClass($class->id), 403);
        $month = $this->month($request->query('bulan'));
        $file = 'rekap-'.str($class->name)->slug().'-'.$month->format('Y-m').'.xlsx';

        return $recap->monthlyXlsx($recap->monthly($class, $month))->download($file);
    }

    public function summary(Request $request, RecapService $recap)
    {
        $from = $this->date($request->query('dari'), CarbonImmutable::today()->startOfMonth());
        $to = $this->date($request->query('sampai'), CarbonImmutable::today());
        if ($to->lessThan($from)) {
            [$from, $to] = [$to, $from];
        }

        return view('recap.summary', $recap->summary($from, $to) + ['from' => $from, 'to' => $to]);
    }

    private function month(?string $value): CarbonImmutable
    {
        try {
            return $value ? CarbonImmutable::createFromFormat('Y-m', $value)->startOfMonth() : CarbonImmutable::today()->startOfMonth();
        } catch (\Throwable) {
            return CarbonImmutable::today()->startOfMonth();
        }
    }

    private function date(?string $value, CarbonImmutable $default): CarbonImmutable
    {
        try {
            return $value ? CarbonImmutable::parse($value)->startOfDay() : $default;
        } catch (\Throwable) {
            return $default;
        }
    }
}
