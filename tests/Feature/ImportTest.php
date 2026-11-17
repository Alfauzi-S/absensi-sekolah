<?php

namespace Tests\Feature;

use App\Models\SchoolClass;
use App\Models\Student;
use App\Services\Xlsx;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ImportTest extends TestCase
{
    public function test_import_csv_and_xlsx_creates_and_updates(): void
    {
        $this->actingAs($this->user('tu'));
        $csv = "NIS;Nama;L/P;Kelas\n1001;Ani;P;X-1\n1002;Budi;L;XI IPA 1\n;Tanpa NIS;L;X-1\n";
        $file = UploadedFile::fake()->createWithContent('siswa.csv', $csv);
        $this->post('/impor', ['file' => $file])->assertSessionHas('ok', 'Impor selesai: 2 siswa baru, 0 diperbarui.');
        $this->assertSame(11, SchoolClass::where('name', 'XI IPA 1')->value('grade'));

        $x = new Xlsx;
        $x->sheet('S', [['NIS', 'Nama Lengkap', 'Kelas', 'No HP Ortu'], ['1001', 'Ani Lestari', 'X-1', '0812'], ['1003', 'Citra', 'X-1', '']]);
        $path = tempnam(sys_get_temp_dir(), 'x');
        $x->save($path);
        $this->post('/impor', ['file' => new UploadedFile($path, 'siswa.xlsx', null, null, true)])->assertSessionHas('ok', 'Impor selesai: 1 siswa baru, 1 diperbarui.');
        $this->assertSame(['Ani Lestari', '0812'], [Student::where('nis', '1001')->value('name'), Student::where('nis', '1001')->value('parent_phone')]);
        $this->assertSame(3, Student::count());
    }

    public function test_reissue_card_invalidates_old_code_and_move_students(): void
    {
        $this->actingAs($this->user('admin'));
        $class = $this->classWithStudents('X-1', 2);
        $target = SchoolClass::create(['name' => 'XI-1', 'grade' => 11]);
        $s = $class->students->first();
        $old = $s->qr_code;
        $this->post("/siswa/{$s->id}/kartu-baru")->assertRedirect();
        $this->assertNotSame($old, $s->fresh()->qr_code);
        $this->assertMatchesRegularExpression('/^['.Student::QR_ALPHABET.']{12}$/', $s->fresh()->qr_code);

        $this->post("/kelas/{$class->id}/pindah", ['students' => $class->students->pluck('id')->all(), 'target' => $target->id]);
        $this->assertSame(2, $target->students()->count());
        $this->delete("/kelas/{$target->id}")->assertSessionHasErrors('class');
    }
}
