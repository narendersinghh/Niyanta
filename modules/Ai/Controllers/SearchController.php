<?php
namespace Modules\Ai\Controllers;
use Core\{Controller, Modules, Response};
use Modules\Ai\Services\{AiClient, AiException, Json, NlSearch, Prompts};

/** Natural-language search: the model only produces a whitelisted filter spec; queries are ours, scoped and parameterised. Falls back to keyword search. */
final class SearchController extends Controller {
  public function index(): Response {
    $u = $this->user();
    $q = mb_substr($this->request->str('q'), 0, 300);
    $entities = NlSearch::allowed($u);
    $data = ['title' => 'Ask your workspace', 'q' => $q, 'spec' => null, 'result' => null, 'groups' => [], 'notice' => null, 'entities' => $entities, 'styles' => ['css/ai.css']];
    if (mb_strlen($q) < 2) { return $this->view('ai::search', $data); }
    if (AiClient::enabled() && AiClient::ready() && $entities) {
      try {
        $r = AiClient::ask('search', Prompts::for('search', ['entities' => $entities]), '<question>'.$q.'</question>', ['max_tokens' => 300]);
        $data['spec'] = Json::filters($r['text']);
        $data['result'] = NlSearch::run($data['spec'], $u);
        return $this->view('ai::search', $data); }
      catch (AiException $e) { $data['notice'] = $e->getMessage().' Showing keyword matches instead.'; $data['spec'] = null; } }
    else { $data['notice'] = !$entities ? 'You do not have access to any searchable records for AI search. Showing keyword matches.' : 'AI search is not available right now. Showing keyword matches instead.'; }
    $data['groups'] = self::keyword(mb_substr($q, 0, 100), $u);
    return $this->view('ai::search', $data); }

  /** Core keyword search through every enabled module's own scoped search hook. */
  public static function keyword(string $q, array $u): array {
    $groups = [];
    if (mb_strlen($q) < 2) { return $groups; }
    foreach (Modules::hooks('search') as $mod => $fn) {
      try { foreach ($fn($q, $u) as $label => $rows) { if ($rows) { $groups[$label] = $rows; } } }
      catch (\Throwable $e) { error_log("AI search fallback $mod: ".$e->getMessage()); } }
    return $groups; } }
