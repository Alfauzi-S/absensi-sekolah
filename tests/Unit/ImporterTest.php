<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Database as DB;
use App\Models\Schedule;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Services\Importer;
use App\Services\Spreadsheet;
use PHPUnit\Framework\TestCase;

/** Impor massal: baris benar masuk, baris rusak dilaporkan dengan nomor baris, tidak membatalkan yang lain. */
final class ImporterTest extends TestCase
{
    private array $f;

    protected function setUp(): void
    {
        $this->f = Fixture::base();
    }

    private function csv(string $content): array
    {
        $path = tempnam(sys_get_temp_dir(), 'imp');
        file_put_contents($path, $content);
        try {
            return Spreadsheet::read($path, 'data.csv');
        } finally {
            unlink($path);
        }
    }

    public function testHeadersAreNormalized(): void
    {
        $s = $this->csv("\xEF\xBB\xBFNIS;Nama Lengkap;JK\n1;Budi;L\n");
        self::assertSame(['nis', 'nama_lengkap', 'jk'], $s['headers']);
        self::assertSame(['1', 'Budi', 'L'], $s['rows'][0]);
    }

    public function testStudentsImportWithBrokenRows(): void
    {
        $sheet = $this->csv(implode("\n", [
            'nis,nisn,nama,jk,kelas,tanggal_lahir,nama_ortu,hp_ortu',
            '24001,0081234567,Aisyah Putri,P,X-A,14/05/2010,Budi,+62 812-3456-7890',
            '24002,,Bagas Pratama,L,X-C,,,',            // gagal: kelas X-C belum ada (tidak dibuat otomatis, cegah salah ketik)
            ',,Tanpa NIS,L,X-A,,,',                      // gagal: NIS kosong
            '24003,,Cici,X,X-A,,,',                      // gagal: jk salah
            '24001,,Aisyah Ganda,P,X-A,,,',              // gagal: NIS ganda di file
            '24004,,Dedi,L,,,,',                         // tanpa kelas
        ]));
        $res = Importer::students($sheet, $this->f['year']);
        self::assertSame(2, $res['created']);
        self::assertSame(0, $res['updated']);
        self::assertSame(1, $res['placed']);
        self::assertSame([3, 4, 5, 6], array_column($res['failed'], 'row'));
        self::assertSame('2010-05-14', Student::findByNis('24001')['birth_date']);
        self::assertSame('081234567890', Student::findByNis('24001')['parent_phone']);
        self::assertNull(SchoolClass::findByName($this->f['year'], 'X-C'));
        self::assertNull(Student::findByNis('24002'), 'baris dengan kelas tak dikenal tidak disimpan setengah');
        self::assertSame('X-A', Student::classIn((int) Student::findByNis('24001')['id'], $this->f['year'])['name']);
        self::assertNull(Student::classIn((int) Student::findByNis('24004')['id'], $this->f['year']));
    }

    public function testStudentsImportUpdatesOrSkipsExisting(): void
    {
        Importer::students($this->csv("nis,nama,jk,kelas\n24001,Aisyah,P,X-A\n"), $this->f['year']);
        $res = Importer::students($this->csv("nis,nama,jk,kelas\n24001,Aisyah Putri,P,X-B\n"), $this->f['year'], true);
        self::assertSame(['created' => 0, 'updated' => 1, 'placed' => 1], array_intersect_key($res, ['created' => 1, 'updated' => 1, 'placed' => 1]));
        $s = Student::findByNis('24001');
        self::assertSame('Aisyah Putri', $s['full_name']);
        self::assertSame('X-B', Student::classIn((int) $s['id'], $this->f['year'])['name'], 'pindah kelas, tidak ganda');
        self::assertSame(1, (int) DB::value('SELECT COUNT(*) FROM class_members WHERE student_id = ?', [(int) $s['id']]));
        $res = Importer::students($this->csv("nis,nama,jk\n24001,Nama Lain,P\n"), $this->f['year'], false);
        self::assertSame(0, $res['updated']);
        self::assertSame('Aisyah Putri', Student::findByNis('24001')['full_name']);
    }

    public function testMissingRequiredColumnAborts(): void
    {
        $this->expectException(\RuntimeException::class);
        Importer::students($this->csv("nisn,nama\n1,Budi\n"), $this->f['year']);
    }

    public function testSchedulesImportDetectsConflictsAndUnknownRefs(): void
    {
        $sheet = $this->csv(implode("\n", [
            'kelas,hari,jam_mulai,jam_selesai,mapel,nip_guru,ruangan',
            'X-A,Senin,1,2,MTK,1001,R-01',
            'X-B,senin,1,2,Bahasa Indonesia,1002,R-02',
            'X-B,Senin,2,3,MTK,1001,',        // gagal: guru 1001 bentrok dengan X-A jam 1–2
            'X-A,Selasa,1,1,FIS,,',           // gagal: mapel tidak ada
            'X-Z,Selasa,1,1,MTK,,',           // gagal: kelas tidak ada
            'X-A,Rabu,3,9,MTK,,',             // gagal: jam 9 tidak terdaftar
            'X-A,Kamis,1,2,MTK,9999,',        // gagal: NIP tidak dikenal
        ]));
        $res = Importer::schedules($sheet, $this->f['semester'], $this->f['year']);
        self::assertSame(2, $res['created']);
        self::assertSame([4, 5, 6, 7, 8], array_column($res['failed'], 'row'));
        self::assertCount(1, Schedule::forClass($this->f['semester'], $this->f['classA']));
        self::assertSame($this->f['room'], (int) Schedule::forClass($this->f['semester'], $this->f['classA'])[0]['room_id']);
    }

    public function testSchedulesReplaceModeClearsClassFirst(): void
    {
        Importer::schedules($this->csv("kelas,hari,jam_mulai,jam_selesai,mapel\nX-A,Senin,1,2,MTK\nX-A,Selasa,1,2,MTK\n"), $this->f['semester'], $this->f['year']);
        $res = Importer::schedules($this->csv("kelas,hari,jam_mulai,jam_selesai,mapel\nX-A,Senin,1,2,MTK\n"), $this->f['semester'], $this->f['year'], true);
        self::assertSame(1, $res['created']);
        self::assertSame([], $res['failed']);
        self::assertCount(1, Schedule::forClass($this->f['semester'], $this->f['classA']));
    }
}
