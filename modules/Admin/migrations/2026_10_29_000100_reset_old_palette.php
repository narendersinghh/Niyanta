<?php
// The redesigned stylesheet owns the palette. Earlier versions stored the old pastel defaults on every save, which
// overrode the new design: drop stored values that equal those old defaults (real custom colours are kept).
return ['up' => [
"DELETE FROM settings WHERE (`key`, LOWER(`value`)) IN (('appearance.primary_color','#7c5cff'),('appearance.sidebar_color','#111116'),('appearance.accent_1','#c8b6ff'),('appearance.accent_2','#9fe8cb'),('appearance.accent_3','#e4f28f'),('appearance.accent_4','#ffb48a'),('appearance.radius','24'),('appearance.font_size','14'),('branding.primary_color','#7c5cff'))",
"DELETE FROM settings WHERE `key` LIKE 'appearance.%' AND `key` NOT IN ('appearance.font_family','appearance.default_theme') AND (`value` IS NULL OR `value`='')",
], 'down' => []];
