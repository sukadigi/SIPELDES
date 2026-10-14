<?php $user = current_user(); ?>
<nav class="topbar navbar navbar-expand-lg">
    <div class="container">
        <a class="brand-lockup" href="/" aria-label="Beranda Desa Sukakerta">
            <span class="brand-mark">SK</span>
            <span><strong>DESA SUKAKERTA</strong><small>PELAYANAN SURAT DIGITAL</small></span>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-label="Buka navigasi"><span class="navbar-toggler-icon"></span></button>
        <div class="collapse navbar-collapse" id="mainNav">
            <div class="navbar-nav ms-auto align-items-lg-center gap-lg-2">
                <a class="nav-link" href="/layanan-surat">Layanan</a>
                <a class="nav-link" href="/cek-status">Cek status</a>
                <?php if ($user): ?>
                    <a class="nav-link" href="/dashboard">Dashboard</a>
                    <form method="post" action="/logout" class="ms-lg-2"><?= csrf_field() ?><button class="btn btn-outline-dark btn-sm" type="submit">Keluar</button></form>
                <?php else: ?>
                    <a class="nav-link" href="/login">Masuk</a>
                    <a class="btn btn-success btn-sm px-3" href="/daftar">Buat akun</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</nav>