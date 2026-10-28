<?php
namespace Core;

/** Base controller with helpers shared by all modules. */
abstract class Controller {
  public function __construct(protected Request $request) {}

  protected function view(string $view, array $data = [], string $layout = 'layouts/app'): Response { return Response::view($view, $data, $layout); }
  protected function json(mixed $data, int $status = 200): Response { return Response::json($data, $status); }
  protected function redirect(string $path): Response { return Response::redirect(str_starts_with($path, 'http') ? $path : url($path)); }
  protected function back(): Response { return Response::back(); }
  protected function validate(array $rules, array $labels = []): array { return Validator::make($this->request->all(), $rules, $labels); }
  protected function authorize(string $perm): void { Gate::authorize($perm); }
  protected function user(): array { return Auth::user() ?? throw new HttpException(401, 'Unauthenticated'); }
  protected function uid(): int { return (int) $this->user()['id']; }
  protected function can(string $perm): bool { return Gate::allows($perm); }
  protected function flash(string $msg, string $type = 'success'): void { Session::flash('toast', ['type' => $type, 'msg' => $msg]); }
  protected function abort(int $code, string $msg = ''): never { throw new HttpException($code, $msg); }
  /** Find a row or 404. */
  protected function findOr404(string $table, int $id): array { return DB::find($table, $id) ?? $this->abort(404, 'Record not found.'); } }
