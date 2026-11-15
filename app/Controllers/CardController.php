<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\AuditLog;
use App\Models\Card;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Services\Access;
use App\Services\CardPdf;
use App\Services\Photo;

/** Kartu QR siswa: terbitkan massal per kelas/sekolah, ganti kartu hilang, cetak PDF (TU/Super Admin). */
final class CardController extends Controller
{
    public function index(): void
    {
        $user = $this->allow('master');
        $year = $this->needYear();
        $this->render('admin/cards/index', [
            'title' => t('Kartu QR'), 'active' => 'cards',
            'classes' => Card::statsByClass((int) $year['id']), 'totals' => Card::totals((int) $year['id']),
            'photoOk' => Photo::available(),
        ]);
    }

    /** Terbitkan kartu untuk semua siswa aktif yang belum punya (satu kelas, atau seluruh sekolah). */
    public function issueBulk(): void
    {
        $user = $this->allow('master');
        $year = $this->needYear();
        $classId = (int) input('class_id') ?: null;
        $class = $classId ? (SchoolClass::find($classId) ?? abort(404, 'Kelas tidak ditemukan')) : null;
        $ids = Card::missingStudentIds((int) $year['id'], $classId);
        foreach ($ids as $sid) {
            Card::issue($sid, (int) $user['id']);
        }
        AuditLog::add($user, 'card.issue_bulk', $classId, 'class', $classId, ['class' => $class['name'] ?? 'semua', 'count' => count($ids)]);
        flash($ids ? 'ok' : 'info', t(':n kartu diterbitkan', ['n' => count($ids)]), $class ? $class['name'] : t('Seluruh sekolah'));
        redirect($class ? 'admin/kelas/' . $class['id'] : 'admin/kartu');
    }

    /** Kartu baru untuk satu siswa (pertama kali, atau pengganti kartu hilang: kartu lama dicabut). */
    public function issue(string $studentId): void
    {
        $user = $this->allow('master');
        $s = Student::find((int) $studentId) ?? abort(404, 'Siswa tidak ditemukan');
        $reason = trim(input('reason'));
        $res = Card::issue((int) $s['id'], (int) $user['id'], $reason);
        AuditLog::add($user, 'card.issue', null, 'student', $s['id'], ['nis' => $s['nis'], 'version' => $res['version'], 'replaced' => $res['replaced'], 'reason' => $reason]);
        flash('ok', $res['replaced'] ? 'Kartu diganti; kartu lama tidak berlaku lagi' : 'Kartu diterbitkan', t('Versi :v · :code', ['v' => $res['version'], 'code' => Card::pretty($res['code'])]));
        redirect('admin/siswa/' . $s['id']);
    }

    public function revoke(string $studentId): void
    {
        $user = $this->allow('master');
        $s = Student::find((int) $studentId) ?? abort(404, 'Siswa tidak ditemukan');
        $card = Card::activeFor((int) $s['id']);
        if (!$card) {
            flash('info', 'Siswa ini tidak punya kartu aktif');
            redirect('admin/siswa/' . $s['id']);
        }
        $reason = trim(input('reason')) ?: 'Dicabut oleh TU';
        Card::revoke((int) $card['id'], $reason);
        AuditLog::add($user, 'card.revoke', null, 'student', $s['id'], ['nis' => $s['nis'], 'version' => $card['version'], 'reason' => $reason]);
        flash('ok', 'Kartu dicabut', t('Kartu versi :v tidak berlaku lagi.', ['v' => $card['version']]));
        redirect('admin/siswa/' . $s['id']);
    }

    /** PDF kartu: ?kelas=<id> satu kelas, ?kelas=semua seluruh sekolah, ?siswa=<id> satu siswa. ?belum=1 hanya yang belum dicetak. */
    public function print(): void
    {
        $user = $this->allow('master');
        $year = $this->needYear();
        $onlyUnprinted = query('belum') === '1';
        $cards = [];
        $ids = [];
        $label = '';
        if (query('siswa') !== '') {
            $s = Student::find((int) query('siswa')) ?? abort(404, 'Siswa tidak ditemukan');
            $c = Card::activeFor((int) $s['id']);
            if (!$c) {
                flash('bad', 'Siswa belum punya kartu aktif', t('Terbitkan kartu terlebih dahulu.'));
                redirect('admin/siswa/' . $s['id']);
            }
            $class = Student::classIn((int) $s['id'], (int) $year['id']);
            $cards[] = ['full_name' => $s['full_name'], 'nis' => $s['nis'], 'class_name' => $class['name'] ?? '-', 'code' => $c['code'], 'version' => (int) $c['version'], 'photo' => Photo::exists((int) $s['id']) ? Photo::path((int) $s['id']) : null];
            $ids[] = (int) $c['id'];
            $label = $s['nis'];
        } else {
            $classes = query('kelas') === 'semua' ? SchoolClass::forYear((int) $year['id']) : [SchoolClass::find((int) query('kelas')) ?? abort(404, 'Kelas tidak ditemukan')];
            foreach ($classes as $class) {
                foreach (Card::forClass((int) $class['id']) as $r) {
                    if (!$r['code'] || ($onlyUnprinted && $r['printed_at'])) {
                        continue;
                    }
                    $cards[] = ['full_name' => $r['full_name'], 'nis' => $r['nis'], 'class_name' => $class['name'], 'code' => $r['code'], 'version' => (int) $r['version'], 'photo' => Photo::exists((int) $r['id']) ? Photo::path((int) $r['id']) : null];
                    $ids[] = (int) $r['card_id'];
                }
            }
            $label = query('kelas') === 'semua' ? 'semua' : str_replace([' ', '/'], '-', $classes[0]['name']);
        }
        $t0 = microtime(true);
        $pdf = CardPdf::build($cards, t('Kartu Siswa :l', ['l' => $label]));
        Card::markPrinted($ids);
        AuditLog::add($user, 'card.print', null, 'cards', null, ['target' => $label, 'count' => count($cards), 'ms' => (int) round((microtime(true) - $t0) * 1000)]);
        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . (query('unduh') === '1' ? 'attachment' : 'inline') . '; filename="kartu-' . $label . '-' . date('Ymd') . '.pdf"');
        header('Cache-Control: no-store');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
        exit;
    }

    /** Foto siswa (di luar public/), hanya untuk yang boleh melihat kelasnya. ?kecil=1 untuk thumbnail. */
    public function photo(string $studentId): void
    {
        $user = \App\Core\Auth::require();
        $s = Student::find((int) $studentId) ?? abort(404, 'Siswa tidak ditemukan');
        $year = \App\Models\AcademicYear::active();
        $class = $year ? Student::classIn((int) $s['id'], (int) $year['id']) : null;
        Access::require($user, 'class:view', $class ? (int) $class['id'] : 0);
        $path = Photo::path((int) $s['id'], query('kecil') === '1');
        if (!is_file($path)) {
            abort(404, 'Foto tidak ada');
        }
        header('Content-Type: image/jpeg');
        header('Cache-Control: private, max-age=300');
        header('Content-Length: ' . (string) filesize($path));
        readfile($path);
        exit;
    }
}
