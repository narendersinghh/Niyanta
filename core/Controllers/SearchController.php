<?php
namespace Core\Controllers;
use Core\{Controller, Modules, Response};

/** Global search across modules; each module scopes results to what the user may see. Results are grouped by category. */
final class SearchController extends Controller {
  private const STYLE = ['client' => ['briefcase', 'peach'], 'contact' => ['user', 'peach'], 'project' => ['folder', 'lav'], 'task' => ['check-square', 'mint'], 'invoice' => ['receipt', 'mint'], 'quotation' => ['file-text', 'lemon'], 'payment' => ['wallet', 'mint'],
    'employee' => ['id-card', 'sky'], 'people' => ['users', 'sky'], 'team' => ['users', 'sky'], 'leave' => ['umbrella', 'sky'], 'holiday' => ['calendar', 'peach'], 'payslip' => ['wallet', 'lav'], 'reimbursement' => ['receipt', 'pink'], 'document' => ['book', 'sky'], 'report' => ['clipboard', 'pink'], 'timesheet' => ['calendar', 'sky'], 'user' => ['users', 'sky']];

  private static function style(string $label): array {
    $l = strtolower($label);
    foreach (self::STYLE as $k => $s) { if (str_contains($l, $k)) { return $s; } }
    return ['search', 'lav']; }

  public function index(): Response {
    $q = mb_substr($this->request->str('q'), 0, 100);
    $groups = [];
    if (mb_strlen($q) >= 2) {
      foreach (Modules::hooks('search') as $mod => $fn) {
        try { foreach ($fn($q, $this->user()) as $label => $rows) { if ($rows) { [$ic, $c] = self::style($label); $groups[] = ['label' => $label, 'icon' => $ic, 'color' => $c, 'count' => count($rows), 'items' => array_map(static fn ($r) => $r + ['url_abs' => url($r['url'])], $rows)]; } } }
        catch (\Throwable $e) { error_log("Search $mod: ".$e->getMessage()); } } }
    if ($this->request->wantsJson()) { return $this->json(['q' => $q, 'total' => array_sum(array_column($groups, 'count')), 'groups' => $groups, 'all_url' => url('/search').'?q='.rawurlencode($q)]); }
    return $this->view('search/index', ['title' => 'Search', 'q' => $q, 'groups' => $groups]); } }
