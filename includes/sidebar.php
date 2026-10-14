<?php
$sidebarLinks = [
    ['label' => 'Ringkasan', 'href' => '/admin', 'permission' => 'dashboard.view'],
    ['label' => 'Pengajuan surat', 'href' => '/admin/pengajuan', 'permission' => 'applications.view'],
    ['label' => 'Template surat', 'href' => '/admin/template', 'permission' => 'templates.manage'],
    ['label' => 'Pengguna & role', 'href' => '/admin/pengguna', 'permission' => 'users.manage'],
    ['label' => 'Laporan', 'href' => '/admin/laporan', 'permission' => 'reports.view'],
    ['label' => 'Audit aktivitas', 'href' => '/admin/audit', 'permission' => 'audit.view'],
];
?>
<aside class="admin-sidebar">
    <p class="sidebar-caption">RUANG KERJA</p>
    <?php foreach ($sidebarLinks as $link): ?>
        <?php if (can($link['permission'])): ?>
            <a class="sidebar-link <?= ($activeNav ?? '') === $link['href'] ? 'active' : '' ?>" href="<?= e($link['href']) ?>"><span><?= e($link['label']) ?></span><span aria-hidden="true">›</span></a>
        <?php endif; ?>
    <?php endforeach; ?>
    <div class="sidebar-office"><strong>Pemerintah Desa Sukakerta</strong><span>Cilamaya Wetan · Karawang</span></div>
</aside>