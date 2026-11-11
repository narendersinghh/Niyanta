<?php
namespace Modules\Meetings\Services;
use Core\{Auth, View};

/** Meeting pieces embedded in other modules' pages: the "Add transcript or notes" section of the Log activity dialog, the notes address hint, the New meeting button + dialog and the upcoming meetings card. */
final class Ui {
  public const PRIVACY = 'Niyanta never records audio. Transcripts come from your meeting platform or notes tool, which tells everyone in the meeting that it is transcribing. Transcript text is sent to your AI provider to create the summary.';

  /** ctx: client_id, project_id (fixed on the project page), project_name, standalone (Meetings page upload form). */
  public static function attachSection(array $ctx): string {
    $u = Auth::user();
    if (!$u || !feature_on('meetings')) { return ''; }
    $addr = !empty($ctx['project_id']) ? Inbox::plusAddress('p', (int) $ctx['project_id']) : (!empty($ctx['client_id']) ? Inbox::plusAddress('c', (int) $ctx['client_id']) : Inbox::address());
    return View::partial('meetings::partials/attach', ['ctx' => $ctx, 'ff' => (bool) Fireflies::keyFor($u), 'addr' => $addr, 'ai' => Summarizer::available()]); }

  /** Card with the project / client plus-address and a copy button (only when the notes inbox address is set). */
  public static function addressHint(string $kind, int $id): string {
    $a = Inbox::plusAddress($kind, $id);
    if (!$a || !feature_on('meetings') || !can('meetings.use')) { return ''; }
    return '<div class="mt-addr"><span class="mt-ic">'.icon('mail', 'sm').'</span><div class="grow"><b class="small">Meeting notes address</b><div class="xs muted">Add it as a guest in the meeting invite, or forward / auto-share notes and recaps to it. They land here as meeting logs.</div></div><code class="mono">'.e($a).'</code><button type="button" class="btn icon sm ghost" data-copy="'.e($a).'" title="Copy address" aria-label="Copy address">'.icon('copy', 'sm').'</button></div>'; }

  /** "New meeting" button for a page (ctx: client_id, project_id fixed), empty when the user may not plan meetings there. */
  public static function newButton(array $ctx = [], string $class = 'btn primary'): string {
    $u = Auth::user();
    if (!$u || !Schedule::canPlan($u, $ctx['client_id'] ?? null, $ctx['project_id'] ?? null)) { return ''; }
    return '<button type="button" class="'.e($class).'" data-modal="mt-new" data-mt-new>'.icon('calendar', 'sm').' New meeting</button>'; }

  /** The New meeting dialog. ctx: client_id / project_id (fixed: the client page fixes the client, the project page both), return (meetings|client|project). */
  public static function newDialog(array $ctx = []): string {
    $u = Auth::user();
    $cid = !empty($ctx['client_id']) ? (int) $ctx['client_id'] : null; $pid = !empty($ctx['project_id']) ? (int) $ctx['project_id'] : null;
    if (!$u || !Schedule::canPlan($u, $cid, $pid)) { return ''; }
    return View::partial('meetings::partials/new_meeting', ['ctx' => ['client_id' => $cid, 'project_id' => $pid, 'return' => $ctx['return'] ?? 'meetings'], 'form' => Schedule::formData($u, $cid, $pid), 'm' => null, 'people' => []]); }

  /** Upcoming meetings card of a client or project page ('' when the feature is off or nothing is planned and the user cannot plan). */
  public static function upcomingCard(array $ctx): string {
    $u = Auth::user();
    if (!$u || !feature_on('meetings') || !can('meetings.use')) { return ''; }
    $cid = !empty($ctx['client_id']) ? (int) $ctx['client_id'] : null; $pid = !empty($ctx['project_id']) ? (int) $ctx['project_id'] : null;
    $rows = Schedule::upcoming($u, $cid, $pid, 5);
    $plan = Schedule::canPlan($u, $cid, $pid);
    if (!$rows && !$plan) { return ''; }
    return View::partial('meetings::partials/upcoming', ['rows' => $rows, 'plan' => $plan, 'compact' => true, 'where' => $pid ? 'none' : ($cid ? 'project' : 'all')]); }

  public static function confidence(string $c): string { return match ($c) { 'high' => '<span class="pill pill-mint">High confidence</span>', 'medium' => '<span class="pill pill-lemon">Medium confidence</span>', 'low' => '<span class="pill pill-peach">Low confidence</span>', default => '<span class="pill pill-gray">No match</span>' }; }

  public static function summaryBadge(string $s): string { return match ($s) { 'done' => '<span class="pill pill-mint">'.icon('sparkles', 'sm').' Summary ready</span>', 'new' => '<span class="pill pill-lav">'.icon('clock', 'sm').' Summary queued</span>', 'summarising' => '<span class="pill pill-lav">'.icon('sparkles', 'sm').' Summarising</span>', 'failed' => '<span class="pill pill-red">'.icon('alert', 'sm').' Summary failed</span>', 'skipped' => '<span class="pill pill-gray">No AI summary</span>', default => '' }; } }
