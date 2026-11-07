<?php
$appName = app_name();
$font = (string) setting('appearance.font_family', 'Plus Jakarta Sans');
$theme = (user()['theme'] ?? 'system') === 'system' ? (string) setting('appearance.default_theme', 'system') : user()['theme'];
$vars = array_filter(['--primary' => setting('appearance.primary_color'), '--sidebar' => setting('appearance.sidebar_color'), '--lav' => setting('appearance.accent_1'), '--mint' => setting('appearance.accent_2'), '--lemon' => setting('appearance.accent_3'), '--peach' => setting('appearance.accent_4'), '--fs' => ($fs = (int) setting('appearance.font_size', 0)) ? $fs.'px' : null, '--r' => ($rad = setting('appearance.radius')) !== null ? (int) $rad.'px' : null], static fn ($v) => $v !== null && $v !== '' && preg_match('/^(#[0-9a-f]{3,8}|\d+px)$/i', (string) $v));
$fav = setting('branding.favicon');
?><!doctype html>
<html lang="en" data-theme="<?= $theme === 'dark' ? 'dark' : 'light' ?>" data-pref="<?= e($theme) ?>" data-guest="<?= user() ? 0 : 1 ?>" data-clock="<?= str_contains((string) setting('general.time_format', 'h:i A'), 'H') ? 24 : 12 ?>">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="<?= e(csrf_token()) ?>"><meta name="base-url" content="<?= e(url('')) ?>"><meta name="robots" content="noindex,nofollow"><?php if (\Core\AutoCron::due() && !\Core\AutoCron::canFinishRequest()): ?><meta name="cron-due" content="1"><?php endif ?><meta name="icons" content="<?= e(asset('img/icons.svg')) ?>">
<title><?= e(isset($title) ? $title.' · '.$appName : $appName) ?></title>
<?php if ($fav): ?><link rel="icon" href="<?= e(url('assets/uploads/branding/'.basename((string) $fav))) ?>"><?php else: ?><link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml"><?php endif ?>
<?php if ($font && !in_array($font, ['system-ui', 'System'], true)): ?><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=<?= e(str_replace(' ', '+', $font)) ?>:wght@400;500;600;700;800&display=swap"><?php endif ?>
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
<?php foreach ($styles ?? [] as $s): ?><link rel="stylesheet" href="<?= e(asset($s)) ?>"><?php endforeach ?>
<style>:root{<?php foreach ($vars as $k => $v): ?><?= $k ?>:<?= e($v) ?>;<?php endforeach ?>--font:"<?= e(preg_replace('/[^\w ]/', '', $font)) ?>",system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}</style>
<script nonce="<?= e(nonce()) ?>">(function(){var d=document.documentElement;if(d.dataset.guest==='1'){try{var t=localStorage.getItem('ny-theme');if(t){d.dataset.pref=t}}catch(e){}}var p=d.dataset.pref;d.dataset.theme=p==='dark'||(p==='system'&&matchMedia('(prefers-color-scheme: dark)').matches)?'dark':'light'})();</script>
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
<?php foreach ($scripts ?? [] as $s): ?><script src="<?= e(asset($s)) ?>" defer></script><?php endforeach ?>
</head>
