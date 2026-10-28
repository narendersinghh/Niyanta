<?php
namespace Modules\Attendance\Controllers;
use Core\{Controller, Response};

/** Shared helpers: run an action and answer with JSON (fetch) or flash + redirect (forms). */
abstract class BaseController extends Controller {
  /** JSON callers get a 422 {error} directly (works around core Router's JSON validation path). */
  protected function validate(array $rules, array $labels = [], ?array $data = null): array {
    try { return \Core\Validator::make($data ?? $this->request->all(), $rules, $labels); }
    catch (\Core\ValidationException $e) {
      if (!$this->request->wantsJson()) { throw $e; }
      throw new \Core\HttpException(422, (string) (array_values($e->errors)[0] ?? 'Invalid input.')); } }

  protected function act(callable $fn, ?string $redirect = null): Response {
    try { $res = $fn(); }
    catch (\DomainException $e) {
      if ($this->request->wantsJson()) { return $this->json(['error' => $e->getMessage()], 422); }
      $this->flash($e->getMessage(), 'error');
      return $redirect ? $this->redirect($redirect) : $this->back(); }
    $msg = is_array($res) ? ($res['message'] ?? 'Done.') : (string) $res;
    if ($this->request->wantsJson()) { return $this->json(is_array($res) ? $res + ['ok' => true] : ['ok' => true, 'message' => $msg]); }
    $this->flash($msg);
    return $redirect ? $this->redirect($redirect) : $this->back(); }

  protected function weekParam(): string { return \Modules\Attendance\Services\TimeService::weekStart(preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $this->request->query('week', '')) ? $this->request->query('week') : null); }

  protected function dateParam(string $k, string $d): string { $v = (string) $this->request->query($k, ''); return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) ? $v : $d; } }
