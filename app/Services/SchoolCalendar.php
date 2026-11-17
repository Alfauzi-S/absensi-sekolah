<?php

namespace App\Services;

use App\Models\Holiday;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;

/** Hari sekolah dan jam scan menurut Pengaturan. */
class SchoolCalendar
{
    public static function isSchoolDay(CarbonInterface $date): bool
    {
        return self::offReason($date) === null;
    }

    /** Alasan libur (nama hari libur / "Akhir pekan") atau null bila hari sekolah. */
    public static function offReason(CarbonInterface $date): ?string
    {
        if (in_array($date->isoWeekday(), Setting::weekendDays(), true)) {
            return 'Akhir pekan';
        }
        $holiday = Holiday::whereDate('date', $date->toDateString())->value('name');

        return $holiday ?: null;
    }

    /** @return array<string,string> tanggal (Y-m-d) => nama libur dalam rentang, termasuk akhir pekan */
    public static function offDays(CarbonInterface $from, CarbonInterface $to): array
    {
        $weekend = Setting::weekendDays();
        $holidays = Holiday::whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get()->mapWithKeys(fn ($h) => [substr($h->date, 0, 10) => $h->name])->all();
        $out = [];
        foreach (CarbonPeriod::create($from, $to) as $d) {
            $key = $d->toDateString();
            if (isset($holidays[$key])) {
                $out[$key] = $holidays[$key];
            } elseif (in_array($d->isoWeekday(), $weekend, true)) {
                $out[$key] = 'Akhir pekan';
            }
        }

        return $out;
    }

    /** Jumlah hari sekolah dalam rentang, dibatasi sampai hari ini. */
    public static function schoolDayCount(CarbonInterface $from, CarbonInterface $to): int
    {
        $today = CarbonImmutable::today();
        if ($from->greaterThan($today)) {
            return 0;
        }
        $to = $to->greaterThan($today) ? $today : $to;
        $days = (int) $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) + 1;

        return $days - count(self::offDays($from, $to));
    }

    public static function at(CarbonInterface $date, string $settingKey): CarbonImmutable
    {
        [$h, $m] = array_map('intval', explode(':', Setting::get($settingKey).':0'));

        return CarbonImmutable::parse($date->toDateString())->setTime($h, $m);
    }
}
