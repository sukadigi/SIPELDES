<?php
declare(strict_types=1);

const APPLICATION_STATUSES = [
    'draft' => 'Draft',
    'pending_review' => 'Menunggu pemeriksaan admin',
    'awaiting_documents' => 'Menunggu kelengkapan dokumen',
    'awaiting_identity' => 'Menunggu verifikasi identitas di kantor desa',
    'identity_verified' => 'Identitas terverifikasi',
    'processing' => 'Sedang diproses operator',
    'pending_approval' => 'Menunggu persetujuan pejabat',
    'approved' => 'Disetujui',
    'issued' => 'Surat diterbitkan',
    'ready_pickup' => 'Siap diambil di kantor desa',
    'picked_up' => 'Sudah diambil',
    'rejected' => 'Ditolak',
    'cancelled' => 'Dibatalkan',
    'revision' => 'Direvisi',
];

function roman_month(int $month): string
{
    return [1 => 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'][$month] ?? 'I';
}

function sanitize_template_html(string $html): string
{
    if (!class_exists(DOMDocument::class)) {
        return e(strip_tags($html));
    }
    $document = new DOMDocument('1.0', 'UTF-8');
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="UTF-8"><div id="template-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $root = $document->getElementById('template-root');
    if (!$root) {
        return e(strip_tags($html));
    }
    $allowed = ['div', 'p', 'br', 'strong', 'b', 'em', 'i', 'u', 'h1', 'h2', 'h3', 'hr', 'table', 'thead', 'tbody', 'tr', 'td', 'th', 'ol', 'ul', 'li', 'span'];
    $clean = static function (DOMNode $parent) use (&$clean, $allowed): void {
        foreach (iterator_to_array($parent->childNodes) as $child) {
            if ($child instanceof DOMElement && !in_array(strtolower($child->tagName), $allowed, true)) {
                while ($child->firstChild) {
                    $parent->insertBefore($child->firstChild, $child);
                }
                $parent->removeChild($child);
                continue;
            }
            if ($child instanceof DOMElement) {
                while ($child->attributes->length > 0) {
                    $child->removeAttributeNode($child->attributes->item(0));
                }
                $clean($child);
            } elseif (!$child instanceof DOMText) {
                $parent->removeChild($child);
            }
        }
    };
    $clean($root);
    $output = '';
    foreach ($root->childNodes as $child) {
        $output .= $document->saveHTML($child);
    }
    return $output;
}

function issue_letter(PDO $database, array $application, array $actor): void
{
    $templateQuery = $database->prepare('SELECT id, body_html FROM letter_templates WHERE letter_type_id = ? AND is_active = 1 ORDER BY id LIMIT 1');
    $templateQuery->execute([$application['letter_type_id']]);
    $template = $templateQuery->fetch();
    if (!$template) {
        throw new DomainException('Template aktif untuk jenis surat ini belum tersedia.');
    }

    $residentQuery = $database->prepare('SELECT r.*, u.name AS account_name FROM residents r JOIN users u ON u.id = r.user_id WHERE r.id = ?');
    $residentQuery->execute([$application['resident_id']]);
    $resident = $residentQuery->fetch();
    if (!$resident || !$resident['verified_at']) {
        throw new DomainException('Identitas warga harus diverifikasi langsung di kantor desa sebelum surat diterbitkan.');
    }

    $ownsTransaction = !$database->inTransaction();
    if ($ownsTransaction) {
        $database->beginTransaction();
    }
    try {
        $registerQuery = $database->prepare('SELECT id, register_number FROM letter_registers WHERE application_id = ?');
        $registerQuery->execute([$application['id']]);
        $register = $registerQuery->fetch();
        if (!$register) {
            $year = (int) date('Y');
            $period = date('Ym');
            $counterTypeId = (int) $database->query('SELECT id FROM letter_types ORDER BY id LIMIT 1')->fetchColumn();
            if ($database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $database->exec('UPDATE register_counters SET next_number = next_number WHERE letter_type_id = ' . $counterTypeId . " AND period = '{$period}'");
            }
            $counterQuery = $database->prepare('SELECT id, next_number FROM register_counters WHERE letter_type_id = ? AND period = ?' . ($database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''));
            $counterQuery->execute([$counterTypeId, $period]);
            $counter = $counterQuery->fetch();
            if (!$counter) {
                try {
                    $database->prepare('INSERT INTO register_counters (letter_type_id, period, next_number) VALUES (?, ?, 1)')->execute([$counterTypeId, $period]);
                } catch (PDOException) {
                    $counterQuery->execute([$counterTypeId, $period]);
                }
                $counterQuery->execute([$counterTypeId, $period]);
                $counter = $counterQuery->fetch();
            }
            $sequence = (int) $counter['next_number'];
            $database->prepare('UPDATE register_counters SET next_number = ? WHERE id = ?')->execute([$sequence + 1, $counter['id']]);
            $codeQuery = $database->prepare('SELECT code FROM letter_types WHERE id = ?');
            $codeQuery->execute([$application['letter_type_id']]);
            $code = $codeQuery->fetchColumn();
            $registerNumber = sprintf('470/%03d/%s/SK-SKT/%s/%d', $sequence, $code, roman_month((int) date('n')), $year);
            $database->prepare('INSERT INTO letter_registers (application_id, register_number, period, sequence_number, assigned_by) VALUES (?, ?, ?, ?, ?)')
                ->execute([$application['id'], $registerNumber, $period, $sequence, $actor['id']]);
            $register = ['register_number' => $registerNumber];
        }

        $payload = json_decode($application['payload'], true, 512, JSON_THROW_ON_ERROR);
        $values = [
            'nama_lengkap' => $resident['full_name'], 'nik' => $resident['nik'] ?? '',
            'tempat_lahir' => $resident['birth_place'] ?? '', 'tanggal_lahir' => $resident['birth_date'] ?? '',
            'alamat' => $resident['address'] ?? '', 'rt' => $resident['rt'] ?? '', 'rw' => $resident['rw'] ?? '',
            'nomor_register' => $register['register_number'], 'tanggal_surat' => date('d-m-Y'),
            'nama_pejabat' => 'Kepala Desa Sukakerta',
        ] + $payload;
        $verificationToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $verificationToken);
        $values['tautan_verifikasi'] = app_url('/verifikasi-surat/' . $verificationToken);
        $body = sanitize_template_html($template['body_html']);
        $snapshot = preg_replace_callback('/\{\{([a-zA-Z0-9_]+)\}\}/', static fn(array $match): string => e($values[$match[1]] ?? ''), $body);
        $snapshot .= '<p>Verifikasi surat: <a href="' . e($values['tautan_verifikasi']) . '">Periksa keaslian</a></p>';
        $snapshotData = json_encode($values, JSON_THROW_ON_ERROR);

        $letterQuery = $database->prepare('SELECT id, snapshot_html, snapshot_data FROM letters WHERE application_id = ?');
        $letterQuery->execute([$application['id']]);
        $letter = $letterQuery->fetch();
        if ($letter) {
            $versionQuery = $database->prepare('SELECT COALESCE(MAX(version), 0) + 1 FROM letter_versions WHERE letter_id = ?');
            $versionQuery->execute([$letter['id']]);
            $version = (int) $versionQuery->fetchColumn();
            $database->prepare('INSERT INTO letter_versions (letter_id, version, reason, snapshot_html, snapshot_data, changed_by) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$letter['id'], $version, 'Penerbitan koreksi', $snapshot, $snapshotData, $actor['id']]);
            $database->prepare('UPDATE letter_verification_tokens SET revoked_at = CURRENT_TIMESTAMP WHERE letter_id = ? AND revoked_at IS NULL')->execute([$letter['id']]);
            $letterId = (int) $letter['id'];
            $database->prepare('UPDATE letters SET template_id = ?, snapshot_html = ?, snapshot_data = ?, verification_token = ?, issued_at = CURRENT_TIMESTAMP, issued_by = ?, status = ? WHERE id = ?')
                ->execute([$template['id'], $snapshot, $snapshotData, $tokenHash, $actor['id'], 'issued', $letterId]);
        } else {
            $database->prepare('INSERT INTO letters (application_id, template_id, status, snapshot_html, snapshot_data, verification_token, issued_at, issued_by) VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, ?)')
                ->execute([$application['id'], $template['id'], 'issued', $snapshot, $snapshotData, $tokenHash, $actor['id']]);
            $letterId = (int) $database->lastInsertId();
            $database->prepare('INSERT INTO letter_versions (letter_id, version, reason, snapshot_html, snapshot_data, changed_by) VALUES (?, 1, ?, ?, ?, ?)')
                ->execute([$letterId, 'Penerbitan awal', $snapshot, $snapshotData, $actor['id']]);
        }

        $database->prepare('INSERT INTO letter_verification_tokens (letter_id, token_hash) VALUES (?, ?)')->execute([$letterId, $tokenHash]);
        if ($ownsTransaction) {
            $database->commit();
        }
    } catch (Throwable $error) {
        if ($ownsTransaction && $database->inTransaction()) {
            $database->rollBack();
        }
        throw $error;
    }
}

function transition_application(PDO $database, int $applicationId, string $newStatus, array $actor, string $note = '', array $residentData = []): void
{
    if (!isset(APPLICATION_STATUSES[$newStatus])) {
        throw new DomainException('Status tujuan tidak dikenal.');
    }
    $statement = $database->prepare('SELECT a.*, t.requires_identity, u.id AS resident_user_id FROM letter_applications a JOIN letter_types t ON t.id = a.letter_type_id JOIN residents r ON r.id = a.resident_id JOIN users u ON u.id = r.user_id WHERE a.id = ?');
    $statement->execute([$applicationId]);
    $application = $statement->fetch();
    if (!$application) {
        throw new DomainException('Pengajuan tidak ditemukan.');
    }

    $allowed = [
        'pending_review' => ['awaiting_documents', 'awaiting_identity', 'rejected', 'cancelled'],
        'awaiting_documents' => ['pending_review', 'awaiting_identity', 'rejected', 'cancelled'],
        'awaiting_identity' => ['identity_verified', 'awaiting_documents', 'rejected', 'cancelled'],
        'identity_verified' => ['processing', 'awaiting_documents', 'cancelled'],
        'processing' => ['pending_approval', 'awaiting_documents', 'revision', 'cancelled'],
        'pending_approval' => ['approved', 'rejected', 'revision'],
        'approved' => ['issued', 'revision', 'cancelled'],
        'issued' => ['ready_pickup', 'revision'],
        'ready_pickup' => ['picked_up', 'revision'],
        'revision' => ['processing', 'pending_approval'],
    ];
    if (!in_array($newStatus, $allowed[$application['status']] ?? [], true)) {
        throw new DomainException('Perubahan status tersebut tidak sesuai alur pengajuan.');
    }

    $requiredPermission = match ($newStatus) {
        'identity_verified', 'awaiting_identity' => 'applications.verify',
        'approved' => 'applications.approve',
        'issued' => 'letters.issue',
        default => 'applications.manage',
    };
    if (!can($requiredPermission)) {
        throw new DomainException('Role Anda tidak berwenang menjalankan tindakan ini.');
    }
    if (in_array($newStatus, ['rejected', 'revision', 'awaiting_documents'], true) && trim($note) === '') {
        throw new DomainException('Alasan atau catatan wajib diisi untuk status ini.');
    }
    if ($newStatus === 'issued') {
        $approvalQuery = $database->prepare('SELECT COUNT(*) FROM letter_approvals WHERE application_id = ? AND decision = ?');
        $approvalQuery->execute([$applicationId, 'approved']);
        if ((int) $approvalQuery->fetchColumn() === 0) {
            throw new DomainException('Persetujuan pejabat berwenang wajib tercatat sebelum penerbitan.');
        }
        if ($application['requires_identity']) {
            $identityQuery = $database->prepare('SELECT verified_at FROM residents WHERE id = ?');
            $identityQuery->execute([$application['resident_id']]);
            if (!$identityQuery->fetchColumn()) {
                throw new DomainException('Verifikasi identitas langsung di kantor desa belum tercatat.');
            }
        }
    }
    $database->beginTransaction();
    try {
        if ($newStatus === 'identity_verified') {
            if (count($residentData) !== 6 || !preg_match('/^\d{16}$/', (string) ($residentData['nik'] ?? ''))) {
                throw new DomainException('Lengkapi NIK 16 digit dan data identitas dari dokumen asli warga.');
            }
            $database->prepare('UPDATE residents SET nik = ?, birth_place = ?, birth_date = ?, address = ?, rt = ?, rw = ? WHERE id = ?')
                ->execute([$residentData['nik'], $residentData['birth_place'], $residentData['birth_date'], $residentData['address'], $residentData['rt'], $residentData['rw'], $application['resident_id']]);
            $database->prepare('UPDATE residents SET verified_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$application['resident_id']]);
            $database->prepare('INSERT INTO resident_verifications (resident_id, verifier_id, status, notes, verified_at) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)')
                ->execute([$application['resident_id'], $actor['id'], 'verified', $note]);
        }
        if ($newStatus === 'approved') {
            $database->prepare('INSERT INTO letter_approvals (application_id, approver_id, decision, notes) VALUES (?, ?, ?, ?)')
                ->execute([$applicationId, $actor['id'], 'approved', $note]);
        }
        if ($newStatus === 'issued') {
            issue_letter($database, $application, $actor);
        }
        $database->prepare('UPDATE letter_applications SET status = ?, rejection_reason = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
            ->execute([$newStatus, $newStatus === 'rejected' ? $note : null, $applicationId]);
        $database->prepare('INSERT INTO letter_status_histories (application_id, actor_id, old_status, new_status, note) VALUES (?, ?, ?, ?, ?)')
            ->execute([$applicationId, $actor['id'], $application['status'], $newStatus, $note]);
        $database->prepare('INSERT INTO notifications (user_id, application_id, message) VALUES (?, ?, ?)')
            ->execute([$application['resident_user_id'], $applicationId, 'Status pengajuan Anda: ' . APPLICATION_STATUSES[$newStatus] . ($note !== '' ? '. Catatan: ' . $note : '')]);
        audit('application.status_changed', 'letter_application', $applicationId, ['from' => $application['status'], 'to' => $newStatus]);
        $database->commit();
    } catch (Throwable $error) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        throw $error;
    }
}