<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database as DB;
use App\Models\AcademicYear;
use App\Models\AuditLog;
use App\Models\SchoolClass;
use App\Models\ScanMetric;
use App\Models\Setting;
use App\Services\Access;
use App\Services\Pilot;
use App\Services\RecapExport;
use App\Services\Xlsx;

/**
 * Pilot dan go-live (PRD Fase 7): laporan pilot (kecepatan nyata per kelas, akurasi vs absen manual guru, masukan),
 * lembar banding per kelas per tanggal, dan formulir masukan untuk semua pengguna.
 */
final class PilotController extends Controller
{
    public function index(): void
    {
        $user = $this->allow('reports');
        $cfg = Pilot::config();
        $from = $cfg['from'] ?: date('Y-m-d', strtotime(today() . ' -6 days'));
        $to = $cfg['to'] ?: today();
        $year = AcademicYear::active();
        $classes = [];
        foreach ($cfg['classes'] as $cid) {
            if ($c = SchoolClass::find($cid)) {
                $classes[] = $c;
            }
        }
        $speed = Pilot::speed(array_column($classes, 'id'), $from, $to);
        $acc = [];
        foreach ($classes as $c) {
            $acc[(int) $c['id']] = Pilot::accuracy((int) $c['id'], $from, $to);
        }
        if (query('format') === 'xlsx') {
            AuditLog::add($user, 'recap.export', null, 'pilot', null, ['format' => 'xlsx']);
            $this->export($classes, $speed, $acc, $from, $to);
        }
        $this->render('admin/pilot/index', [
            'title' => t('Pilot dan Go-live'), 'active' => 'pilot', 'cfg' => $cfg, 'from' => $from, 'to' => $to, 'classes' => $classes,
            'allClasses' => $year ? SchoolClass::forYear((int) $year['id']) : [], 'speed' => $speed, 'acc' => $acc,
            'feedback' => DB::all('SELECT f.*, u.name AS user_name, u.role FROM feedback f LEFT JOIN users u ON u.id = f.user_id ORDER BY f.status ASC, f.id DESC LIMIT 200'),
            'canEdit' => Access::can($user, 'settings'),
        ]);
    }

    public function save(): void
    {
        $user = $this->allow('settings');
        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['classes'] ?? []))))), 0, 4);
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', input('from')) ? input('from') : '';
        $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', input('to')) ? input('to') : '';
        if ($from !== '' && $to !== '' && $to < $from) {
            flash('bad', 'Tanggal pilot tidak valid', t('Tanggal selesai harus sesudah tanggal mulai.'));
            redirect('admin/pilot');
        }
        Setting::setMany(['pilot_classes' => implode(',', $ids), 'pilot_from' => $from, 'pilot_to' => $to]);
        AuditLog::add($user, 'pilot.update', null, null, null, ['classes' => $ids, 'from' => $from, 'to' => $to]);
        flash('ok', 'Pengaturan pilot disimpan');
        redirect('admin/pilot');
    }

    /** GET /admin/pilot/banding/{id}?tanggal= : absen manual guru vs sistem untuk satu kelas satu tanggal. */
    public function compare(string $id): void
    {
        $user = Auth::require();
        $class = SchoolClass::find((int) $id) ?? abort(404, 'Kelas tidak ditemukan');
        Access::require($user, 'class:recap', (int) $class['id']);
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', query('tanggal')) ? query('tanggal') : today();
        $rows = Pilot::compare((int) $class['id'], $date);
        $this->render('admin/pilot/compare', [
            'title' => t('Banding Absen Manual · :c', ['c' => $class['name']]), 'active' => 'pilot', 'back' => 'admin/pilot',
            'class' => $class, 'date' => $date, 'rows' => $rows, 'canEdit' => Access::can($user, 'class:manage', (int) $class['id']),
            'filled' => count(array_filter($rows, static fn ($r) => $r['manual'] !== null)),
            'diff' => count(array_filter($rows, static fn ($r) => $r['match'] === false)),
        ]);
    }

    public function compareSave(string $id): void
    {
        $user = Auth::require();
        $class = SchoolClass::find((int) $id) ?? abort(404, 'Kelas tidak ditemukan');
        Access::require($user, 'class:manage', (int) $class['id']);
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', input('tanggal')) ? input('tanggal') : abort(400, 'Tanggal tidak valid');
        $statuses = array_map('strval', (array) ($_POST['status'] ?? []));
        $n = Pilot::saveManual((int) $class['id'], $date, $statuses, (int) $user['id']);
        $explained = 0;
        foreach ((array) ($_POST['explain'] ?? []) as $sid => $text) {
            $row = DB::one('SELECT id, explanation FROM pilot_checks WHERE student_id = ? AND check_date = ? AND class_id = ?', [(int) $sid, $date, (int) $class['id']]);
            $text = trim((string) $text);
            if ($row && $text !== (string) $row['explanation']) {
                Pilot::explain((int) $row['id'], $text, (int) $user['id']);
                $explained++;
            }
        }
        AuditLog::add($user, 'pilot.manual', (int) $class['id'], 'class', $class['id'], ['date' => $date, 'rows' => $n, 'explained' => $explained]);
        flash('ok', 'Absen manual disimpan', t(':n siswa, :e penjelasan selisih.', ['n' => $n, 'e' => $explained]));
        redirect('admin/pilot/banding/' . $class['id'], ['tanggal' => $date]);
    }

    public function compareImport(string $id): void
    {
        $user = Auth::require();
        $class = SchoolClass::find((int) $id) ?? abort(404, 'Kelas tidak ditemukan');
        Access::require($user, 'class:manage', (int) $class['id']);
        $f = $_FILES['file'] ?? null;
        if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) {
            flash('bad', 'File belum dipilih');
            redirect('admin/pilot/banding/' . $class['id']);
        }
        try {
            $r = Pilot::importCsv((int) $class['id'], (string) $f['tmp_name'], (string) $f['name'], (int) $user['id']);
        } catch (\RuntimeException $e) {
            flash('bad', 'File tidak bisa dibaca', $e->getMessage());
            redirect('admin/pilot/banding/' . $class['id']);
        }
        AuditLog::add($user, 'pilot.manual', (int) $class['id'], 'class', $class['id'], ['import' => $r['saved'], 'errors' => count($r['errors'])]);
        $err = array_slice(array_map(static fn ($l, $m) => t('baris :l: :m', ['l' => $l, 'm' => $m]), array_keys($r['errors']), $r['errors']), 0, 5);
        flash($r['errors'] ? 'warn' : 'ok', 'Absen manual diimpor', t(':n baris tersimpan.', ['n' => $r['saved']]) . ($err ? ' ' . implode('; ', $err) : ''));
        redirect('admin/pilot/banding/' . $class['id'], ['tanggal' => query('tanggal') ?: today()]);
    }

    /** Formulir masukan (semua pengguna). */
    public function feedbackForm(): void
    {
        $user = Auth::require();
        $this->render('admin/pilot/feedback', ['title' => t('Kirim Masukan'), 'active' => 'feedback',
            'mine' => DB::all('SELECT * FROM feedback WHERE user_id = ? ORDER BY id DESC LIMIT 20', [(int) $user['id']])]);
    }

    public function feedbackStore(): void
    {
        $user = Auth::require();
        $topic = array_key_exists(input('topic'), Pilot::TOPICS) ? input('topic') : 'lainnya';
        $msg = mb_substr(trim(input('message')), 0, 2000);
        $rating = (int) input('rating');
        if (mb_strlen($msg) < 5) {
            flash('bad', 'Masukan terlalu pendek', t('Tulis minimal satu kalimat.'));
            redirect('admin/masukan');
        }
        DB::insert('feedback', ['user_id' => (int) $user['id'], 'topic' => $topic, 'rating' => $rating >= 1 && $rating <= 5 ? $rating : null, 'message' => $msg, 'status' => 'new', 'created_at' => now()]);
        flash('ok', 'Terima kasih, masukan terkirim', t('Tim pengelola akan menindaklanjuti.'));
        redirect('admin/masukan');
    }

    public function feedbackDone(string $id): void
    {
        $this->allow('settings');
        DB::update('feedback', ['status' => 'done', 'handled_note' => mb_substr(trim(input('note')), 0, 500) ?: null], 'id = ?', [(int) $id]);
        flash('ok', 'Masukan ditandai selesai');
        redirect('admin/pilot');
    }

    private function export(array $classes, array $speed, array $acc, string $from, string $to): never
    {
        $x = new Xlsx();
        $out = RecapExport::kop(t('LAPORAN PILOT ABSENSI QR'), date_id($from) . ' s.d. ' . date_id($to));
        $out[] = array_map(static fn ($h) => ['v' => $h, 's' => 'th'], [t('Kelas'), t('Tanggal'), t('Scan USB'), 'p50 (ms)', 'p95 (ms)', t('Target p95'), t('Lulus')]);
        foreach ($classes as $c) {
            foreach ($speed[(int) $c['id']]['days'] as $d => $m) {
                $out[] = [$c['name'], $d, $m['total']['n'], $m['total']['p50'], $m['total']['p95'], ScanMetric::TARGET_USB, $m['total']['p95'] !== null && $m['total']['p95'] <= ScanMetric::TARGET_USB ? t('Ya') : t('Tidak')];
            }
            $tot = $speed[(int) $c['id']]['total']['total'];
            $out[] = [['v' => $c['name'] . ' · ' . t('seluruh pilot'), 's' => 'b'], '', $tot['n'], $tot['p50'], $tot['p95'], ScanMetric::TARGET_USB, ($p = Pilot::speedPass($speed[(int) $c['id']]['total'])) === null ? t('Data kurang') : ($p ? t('Ya') : t('Tidak'))];
        }
        $x->sheet(t('Kecepatan'), $out, ['widths' => [24, 12, 10, 10, 10, 10, 10]]);

        $out = RecapExport::kop(t('AKURASI: SISTEM vs ABSEN MANUAL GURU'), date_id($from) . ' s.d. ' . date_id($to));
        $out[] = array_map(static fn ($h) => ['v' => $h, 's' => 'th'], [t('Kelas'), t('Tanggal'), 'NIS', t('Nama'), t('Sistem'), t('Manual'), t('Jam scan'), t('Penjelasan')]);
        foreach ($classes as $c) {
            foreach (array_keys($acc[(int) $c['id']]['days']) as $d) {
                foreach (Pilot::compare((int) $c['id'], (string) $d) as $r) {
                    if ($r['match'] === false) {
                        $out[] = [$c['name'], $d, $r['nis'], $r['full_name'], $r['system'] ?: '—', $r['manual'], $r['first_scan'] ?? '', (string) $r['explanation']];
                    }
                }
            }
            $a = $acc[(int) $c['id']];
            $out[] = [['v' => $c['name'] . ' · ' . t(':d hari, :r baris, cocok :m, selisih :s, belum dijelaskan :u', ['d' => count($a['days']), 'r' => $a['rows'], 'm' => $a['match'], 's' => $a['diff'], 'u' => $a['unexplained']]), 's' => 'b']];
        }
        $x->sheet(t('Akurasi'), $out, ['widths' => [12, 12, 12, 28, 8, 8, 9, 50]]);

        $out = RecapExport::kop(t('MASUKAN PENGGUNA'), date_id($from) . ' s.d. ' . date_id($to));
        $out[] = array_map(static fn ($h) => ['v' => $h, 's' => 'th'], [t('Waktu'), t('Pengguna'), t('Topik'), t('Nilai'), t('Masukan'), t('Status')]);
        foreach (DB::all('SELECT f.*, u.name AS user_name FROM feedback f LEFT JOIN users u ON u.id = f.user_id ORDER BY f.id') as $f) {
            $out[] = [substr($f['created_at'], 0, 16), (string) $f['user_name'], t(Pilot::TOPICS[$f['topic']] ?? $f['topic']), $f['rating'] ?? '', ['v' => $f['message'], 's' => 'l'], $f['status'] === 'done' ? t('Selesai') . ($f['handled_note'] ? ': ' . $f['handled_note'] : '') : t('Baru')];
        }
        $x->sheet(t('Masukan'), $out, ['widths' => [16, 20, 16, 6, 60, 24]]);
        $x->download('laporan-pilot-' . $from . '-' . $to . '.xlsx');
    }
}
