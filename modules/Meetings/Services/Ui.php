<?php
namespace Modules\Meetings\Services;
use Core\{Auth, View};

/** Meeting pieces embedded in other modules' pages: the "Add transcript or notes" section of the Log activity dialog and the notes address hint. */
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

  public static function confidence(string $c): string { return match ($c) { 'high' => '<span class="pill pill-mint">High confidence</span>', 'medium' => '<span class="pill pill-lemon">Medium confidence</span>', 'low' => '<span class="pill pill-peach">Low confidence</span>', default => '<span class="pill pill-gray">No match</span>' }; }

  public static function summaryBadge(string $s): string { return match ($s) { 'done' => '<span class="pill pill-mint">'.icon('sparkles', 'sm').' Summary ready</span>', 'new' => '<span class="pill pill-lav">'.icon('clock', 'sm').' Summary queued</span>', 'summarising' => '<span class="pill pill-lav">'.icon('sparkles', 'sm').' Summarising</span>', 'failed' => '<span class="pill pill-red">'.icon('alert', 'sm').' Summary failed</span>', 'skipped' => '<span class="pill pill-gray">No AI summary</span>', default => '' }; } }
