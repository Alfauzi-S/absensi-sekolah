<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\AuditLog;

/** Audit log seluruh sistem (khusus Super Admin). */
final class AuditController extends Controller
{
    private const PER_PAGE = 40;

    public function index(): void
    {
        $this->allow('audit');
        $group = array_key_exists(query('jenis'), AuditLog::GROUPS) ? query('jenis') : '';
        $q = trim(query('q'));
        $page = $this->page();
        $total = AuditLog::count($group, $q);
        $this->render('admin/audit', [
            'title' => t('Audit Log'), 'active' => 'audit', 'group' => $group, 'q' => $q,
            'items' => AuditLog::list($group, $q, self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'page' => $page, 'pages' => (int) ceil($total / self::PER_PAGE), 'total' => $total,
        ]);
    }
}
