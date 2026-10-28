<?php
namespace Core\Controllers;
use Core\{Controller, Modules, Response};

/** Global search across modules; each module scopes results to what the user may see. */
final class SearchController extends Controller {
  public function index(): Response {
    $q = mb_substr($this->request->str('q'), 0, 100);
    $groups = [];
    if (mb_strlen($q) >= 2) {
      foreach (Modules::hooks('search') as $mod => $fn) {
        try { foreach ($fn($q, $this->user()) as $label => $rows) { if ($rows) { $groups[$label] = $rows; } } }
        catch (\Throwable $e) { error_log("Search $mod: ".$e->getMessage()); } } }
    if ($this->request->wantsJson()) { return $this->json(['q' => $q, 'groups' => $groups]); }
    return $this->view('search/index', ['title' => 'Search', 'q' => $q, 'groups' => $groups]); } }
