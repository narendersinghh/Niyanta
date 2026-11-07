<?php
use Modules\Meetings\Controllers\MeetingController;
use Modules\Meetings\Services\{Fireflies, Inbox, MeetingAccess, MeetingService, Summarizer};

return [
  'key' => 'meetings', 'name' => 'Meeting notes & AI summaries', 'icon' => 'message', 'order' => 12, 'version' => '1.0.0', 'requires' => ['crm'],
  'description' => 'Meeting transcripts and notes from any platform (upload, notes inbox, Fireflies) become client logs, summarised by Goraiya. Never records audio.',
  'permissions' => [
    'meetings.use' => ['label' => 'Upload meeting transcripts, sort them and see meetings of their clients and projects', 'roles' => ['manager']],
    'meetings.manage' => ['label' => 'See every meeting incl. the unmatched notes inbox, check the inbox, use the company Fireflies key', 'roles' => []],
  ],
  'nav' => [
    ['label' => 'Meetings', 'icon' => 'message', 'color' => '#7C5CFF', 'url' => '/meetings', 'perm' => 'meetings.use', 'feature' => 'meetings', 'section' => 'clients', 'order' => 2],
  ],
  'routes' => function (Core\Router $r): void {
    $u = 'meetings.use';
    $r->get('/meetings', [MeetingController::class, 'index'], $u);
    $r->post('/meetings/preview', [MeetingController::class, 'preview'], ['perm' => $u, 'throttle' => [60, 300]]);
    $r->post('/meetings/upload', [MeetingController::class, 'upload'], ['perm' => $u, 'throttle' => [30, 300]]);
    $r->get('/meetings/fireflies/recent', [MeetingController::class, 'fireflyRecent'], ['perm' => $u, 'throttle' => [20, 300]]);
    $r->post('/meetings/connections/fireflies', [MeetingController::class, 'saveFireflies'], ['perm' => $u, 'throttle' => [10, 300]]);
    $r->post('/meetings/connections/fireflies/delete', [MeetingController::class, 'deleteFireflies'], $u);
    $r->post('/meetings/connections/fireflies/fetch', [MeetingController::class, 'fetchFireflies'], ['perm' => $u, 'throttle' => [6, 300]]);
    $r->post('/meetings/inbox/check', [MeetingController::class, 'checkInbox'], ['perm' => 'meetings.manage', 'throttle' => [6, 300]]);
    $r->get('/meetings/{id}', [MeetingController::class, 'show'], $u);
    $r->post('/meetings/{id}/assign', [MeetingController::class, 'assign'], $u);
    $r->post('/meetings/{id}/dismiss', [MeetingController::class, 'dismiss'], $u);
    $r->post('/meetings/{id}/summarise', [MeetingController::class, 'summarise'], ['perm' => $u, 'throttle' => [10, 300]]);
    $r->post('/meetings/{id}/actions', [MeetingController::class, 'actions'], $u);
    $r->post('/meetings/{id}/delete', [MeetingController::class, 'destroy'], $u);
  },
  'widgets' => [
    ['zone' => 'side', 'order' => 22, 'perm' => 'meetings.use', 'feature' => 'meetings', 'render' => static function (array $u): string {
      [$w, $p] = MeetingAccess::scope($u, 'mt');
      $n = (int) Core\DB::value("SELECT COUNT(*) FROM meeting_transcripts mt WHERE mt.status='to_sort' AND $w", $p);
      if (!$n) { return ''; }
      return '<div class="card"><div class="card-head"><h3>Meeting notes to sort</h3><span class="pill pill-lemon">'.$n.'</span></div><p class="small muted">Notes arrived without a clear client or project. Pick where they belong.</p><a class="btn sm soft" href="'.e(url('/meetings?tab=sort')).'">'.icon('message', 'sm').' Open meetings</a></div>'; }],
  ],
  'search' => static function (string $q, array $u): array {
    if (!feature_on('meetings') || !Core\Gate::allows('meetings.use', $u)) { return []; }
    [$w, $p] = MeetingAccess::scope($u, 'mt');
    $rows = Core\DB::all("SELECT mt.id, mt.title, mt.meeting_at, mt.created_at FROM meeting_transcripts mt WHERE $w AND mt.title LIKE ? ORDER BY mt.id DESC LIMIT 8", [...$p, Core\DB::like($q)]);
    return ['Meetings' => array_map(static fn ($r) => ['title' => $r['title'], 'meta' => fdate($r['meeting_at'] ?: $r['created_at']), 'url' => '/meetings/'.$r['id'], 'icon' => 'message'], $rows)]; },
  'cron' => [
    'inbox' => ['feature' => 'meetings', 'every' => 5, 'run' => static fn (): string => Inbox::configured() ? Inbox::run() : 'inbox not configured'],
    'fireflies' => ['feature' => 'meetings', 'every' => 15, 'run' => static fn (): string => Fireflies::syncAll()],
    'summaries' => ['feature' => 'meetings', 'every' => 1, 'run' => static fn (): string => Summarizer::runQueue()],
    'retention' => ['feature' => 'meetings', 'daily' => '03:40', 'run' => static fn (): string => MeetingService::retention()],
  ],
];
