<?php
$pageTitle = $pageTitle ?? 'SIPEDES | Desa Sukakerta';
$user = current_user();
?><!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Pelayanan surat digital Pemerintah Desa Sukakerta, Kecamatan Cilamaya Wetan, Kabupaten Karawang.">
    <meta name="robots" content="<?= str_starts_with($_SERVER['REQUEST_URI'] ?? '/', '/admin') || isset($user) ? 'noindex,nofollow' : 'index,follow' ?>">
    <title><?= e($pageTitle) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="/assets/app.css" rel="stylesheet">
</head>
<body>