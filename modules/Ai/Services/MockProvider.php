<?php
namespace Modules\Ai\Services;

/** Offline provider for development and tests. Only usable when config('debug') is true (enforced by AiClient). */
final class MockProvider implements ProviderInterface {
  public function name(): string { return 'mock'; }
  public function model(): string { return 'mock-1'; }

  public function complete(array $messages, array $opts): array {
    $user = (string) end($messages)['content'];
    if (str_contains($user, '#fail')) { throw new AiException(HttpProvider::friendly(503), 'Mock provider simulated failure'); }
    $feature = (string) ($opts['feature'] ?? '');
    $text = match ($feature) {
      'tasks' => "Here are the tasks I found:\n```json\n".json_encode(['tasks' => $this->tasks($user)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n```",
      'search' => json_encode($this->filters($user), JSON_UNESCAPED_UNICODE),
      default => $this->prose($feature, $user) };
    return ['text' => $text, 'input_tokens' => (int) ceil(mb_strlen(implode(' ', array_column($messages, 'content'))) / 4), 'output_tokens' => (int) ceil(mb_strlen($text) / 4), 'model' => 'mock-1']; }

  private function tasks(string $user): array {
    $src = preg_match('/<notes>(.*?)<\/notes>/s', $user, $m) ? $m[1] : $user;
    $out = [];
    foreach (preg_split('/\R+|(?<=[.;])\s+/', $src) ?: [] as $i => $line) {
      $line = trim($line, " \t-*•.;");
      if (mb_strlen($line) < 4) { continue; }
      $out[] = ['title' => mb_substr(ucfirst($line), 0, 80), 'description' => 'From notes: '.$line, 'priority' => ['high', 'medium', 'low'][count($out) % 3], 'due_in_days' => 2 + 3 * count($out)];
      if (count($out) >= 5) { break; } }
    return $out ?: [['title' => 'Review requirements', 'description' => 'Go through the notes with the team.', 'priority' => 'medium', 'due_in_days' => 3]]; }

  private function filters(string $user): array {
    $q = mb_strtolower(preg_match('/<question>(.*?)<\/question>/s', $user, $m) ? $m[1] : $user);
    $entity = 'clients';
    foreach (['invoice' => 'invoices', 'bill' => 'invoices', 'task' => 'tasks', 'todo' => 'tasks', 'project' => 'projects', 'employee' => 'employees', 'staff' => 'employees', 'client' => 'clients'] as $k => $v) { if (str_contains($q, $k)) { $entity = $v; break; } }
    $stop = ['show', 'me', 'my', 'all', 'the', 'list', 'find', 'which', 'are', 'with', 'for', 'that', 'overdue', 'open', 'active', 'invoices', 'invoice', 'tasks', 'task', 'projects', 'project', 'clients', 'client', 'employees', 'employee', 'assigned', 'to', 'in', 'of', 'a', 'an'];
    $words = array_values(array_diff(preg_split('/[^\p{L}\p{N}]+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [], $stop));
    return ['entity' => $entity, 'keywords' => implode(' ', array_slice($words, 0, 3)), 'status' => str_contains($q, 'active') ? 'active' : null, 'date_from' => null, 'date_to' => null, 'assignee_me' => str_contains($q, ' my ') || str_starts_with($q, 'my '), 'overdue' => str_contains($q, 'overdue')]; }

  private function prose(string $feature, string $user): string {
    $lines = substr_count($user, "\n") + 1;
    $title = ['client_summary' => 'Client summary', 'meeting' => 'Meeting summary', 'email' => 'Email draft', 'followups' => 'Follow-up suggestions', 'project_report' => 'Project status report', 'reminder' => 'Payment reminder', 'test' => 'Connection test'][$feature] ?? 'Answer';
    if ($feature === 'test') { return 'OK - the mock provider is responding.'; }
    if (in_array($feature, ['email', 'reminder'], true)) { return "Subject: Quick note from our team\n\nHi there,\n\nI hope you are well. This is a mock draft generated in debug mode from {$lines} lines of context.\n\nBest regards,\nThe team"; }
    return "{$title} (mock, debug mode)\n\n• Context received: {$lines} lines.\n• Key point: everything is on track.\n• Next step: schedule a check-in next week.\n\nAction items:\n1. Share an update with the client.\n2. Review open items."; } }
