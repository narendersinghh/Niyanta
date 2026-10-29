<?php
namespace Modules\Ai\Controllers;
use Core\{Audit, Controller, DB, Response};
use Modules\Ai\Services\{AiException, Assistant, Tools};

/** JSON endpoints behind the chat drawer. Users only ever see their own conversations. */
final class ChatController extends Controller {
  /** /ai opens the drawer on the dashboard (optionally on a conversation). */
  public function index(): Response {
    $c = $this->request->int('c');
    return $this->redirect('/?ai=open'.($c > 0 ? '&c='.$c : '')); }

  public function conversations(): Response {
    $rows = DB::all('SELECT id, title, updated_at FROM ai_conversations WHERE user_id=? ORDER BY updated_at DESC, id DESC LIMIT 30', [$this->uid()]);
    return $this->json(['items' => array_map(static fn ($r) => ['id' => (int) $r['id'], 'title' => $r['title'], 'ago' => ago($r['updated_at'])], $rows)]); }

  public function show(int $id): Response {
    $c = Assistant::conversation($this->user(), $id);
    $msgs = []; $tools = [];
    foreach (DB::all('SELECT id, role, content, tool_name, created_at FROM ai_messages WHERE conversation_id=? ORDER BY id LIMIT 400', [$id]) as $m) {
      if ($m['role'] === 'tool') { $tools[] = $m['tool_name']; continue; }
      $msgs[] = ['id' => (int) $m['id'], 'role' => $m['role'], 'content' => (string) $m['content'], 'tools' => $m['role'] === 'assistant' ? self::labels($tools) : [], 'at' => fdatetime($m['created_at'])];
      if ($m['role'] === 'assistant') { $tools = []; } }
    return $this->json(['id' => (int) $c['id'], 'title' => $c['title'], 'messages' => array_slice($msgs, -80)]); }

  public function chat(): Response {
    $text = (string) $this->request->input('message', '');
    if (mb_strlen($text) > 2000) { return $this->json(['error' => 'Please keep your message under 2000 characters.'], 422); }
    try { $r = Assistant::send($this->user(), $this->request->int('conversation_id') ?: null, $text); }
    catch (AiException $e) { return $this->json(['error' => $e->getMessage()], match ($e->status) { 'blocked' => 429, 'disabled' => 503, 'invalid' => 422, default => 502 }); }
    return $this->json(['conversation' => ['id' => (int) $r['conversation']['id'], 'title' => $r['conversation']['title']], 'message' => ['id' => (int) $r['message']['id'], 'role' => 'assistant', 'content' => $r['message']['content'], 'tools' => self::labels($r['tools']), 'at' => fdatetime($r['message']['created_at'])]]); }

  public function destroy(int $id): Response {
    Assistant::conversation($this->user(), $id);
    DB::delete('ai_conversations', 'id=? AND user_id=?', [$id, $this->uid()]);
    Audit::log('ai.conversation.delete', 'ai_conversation', $id);
    return $this->json(['ok' => true]); }

  private static function labels(array $names): array { return array_values(array_unique(array_map([Tools::class, 'label'], array_filter($names)))); } }
