<?php
namespace Modules\Crm\Services;

/** Small view helpers shared by the CRM pages (stat tiles, "more" menus, file badges). Output is always escaped here. */
final class Ui {
  /** Stat tile in the design-system markup; the icon chip is placed on the right by app.css. */
  public static function tile(string $color, string $icon, string $num, string $label, ?string $href = null, string $title = ''): string {
    $inner = '<span class="tile-ic">'.icon($icon).'</span><div><div class="num">'.e($num).'</div><div class="lbl">'.e($label).'</div></div>';
    $t = $title !== '' ? ' title="'.e($title).'"' : '';
    return $href ? '<a class="tile bg-'.e($color).'" href="'.e(url($href)).'"'.$t.'>'.$inner.'</a>' : '<div class="tile bg-'.e($color).'"'.$t.'>'.$inner.'</div>'; }

  /** Secondary actions collapsed into a "more" dropdown. $items are trusted HTML fragments (links / POST forms). */
  public static function more(array $items, string $label = 'More actions', bool $text = false): string {
    $items = array_values(array_filter($items));
    if (!$items) { return ''; }
    $sum = $text ? '<summary class="btn ghost">'.icon('more', 'sm').' More</summary>' : '<summary class="btn icon ghost" aria-label="'.e($label).'" title="'.e($label).'">'.icon('more', 'sm').'</summary>';
    return '<details class="dd crm-more">'.$sum.'<div class="dd-menu">'.implode('', $items).'</div></details>'; }

  /** Menu entry: link. */
  public static function link(string $href, string $icon, string $label, string $attrs = ''): string { return '<a href="'.e(url($href)).'"'.$attrs.'>'.icon($icon, 'sm').' '.e($label).'</a>'; }

  /** Menu entry: POST form button (CSRF protected, optional confirm). */
  public static function post(string $action, string $icon, string $label, string $confirm = '', bool $danger = false, array $hidden = []): string {
    $h = '';
    foreach ($hidden as $k => $v) { $h .= '<input type="hidden" name="'.e($k).'" value="'.e($v).'">'; }
    return '<form method="post" action="'.e(url($action)).'"'.($confirm !== '' ? ' data-confirm="'.e($confirm).'"' : '').'>'.csrf_field().$h.'<button type="submit"'.($danger ? ' class="crm-danger"' : '').'>'.icon($icon, 'sm').' '.e($label).'</button></form>'; }

  /** Coloured file-type badge for document lists. */
  public static function ext(string $name): string {
    $x = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $c = match (true) { $x === 'pdf' => 'peach', in_array($x, ['doc', 'docx', 'txt', 'rtf'], true) => 'sky', in_array($x, ['xls', 'xlsx', 'csv'], true) => 'mint', in_array($x, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'], true) => 'pink', in_array($x, ['zip', 'rar', '7z'], true) => 'lemon', default => 'lav' };
    return '<span class="crm-ext crm-c-'.$c.'">'.e(strtoupper(mb_substr($x, 0, 4)) ?: 'FILE').'</span>'; }

  /** Human file size. */
  public static function size(int|float|string $bytes): string { $b = (float) $bytes; return $b >= 1048576 ? number_format($b / 1048576, 1).' MB' : max(1, (int) round($b / 1024)).' KB'; }

  /** Quantity without trailing zeros. */
  public static function qty(mixed $x): string { $s = (string) $x; return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s; }

  /** Days between today and a date (positive = in the past). */
  public static function daysPast(?string $date): int { return $date ? (int) floor((strtotime(date('Y-m-d')) - strtotime($date)) / 86400) : 0; } }
