<?php
namespace Modules\Ai\Controllers;
use Core\{Controller, DB, Response};
use Modules\Ai\Services\Usage;

/** AI usage & cost overview by month, user, feature, plus recent errors. */
final class UsageController extends Controller {
  public function index(): Response {
    $m = $this->request->str('month');
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m)) { $m = date('Y-m'); }
    $from = $m.'-01 00:00:00';
    $to = date('Y-m-d H:i:s', strtotime($from.' +1 month'));
    $w = 'created_at>=? AND created_at<?'; $p = [$from, $to];
    $tot = DB::first("SELECT COALESCE(SUM(CASE WHEN status='ok' THEN input_tokens+output_tokens END),0) AS tokens, COALESCE(SUM(CASE WHEN status='ok' THEN input_tokens END),0) AS input, COALESCE(SUM(CASE WHEN status='ok' THEN output_tokens END),0) AS output, SUM(status='ok') AS ok, SUM(status='error') AS errors, SUM(status='blocked') AS blocked, COUNT(DISTINCT user_id) AS users FROM ai_usage WHERE $w", $p);
    $start = date('Y-m-01 00:00:00', strtotime($from.' -5 months'));
    $trend = DB::pairs("SELECT DATE_FORMAT(created_at,'%Y-%m') m, SUM(input_tokens+output_tokens) FROM ai_usage WHERE status='ok' AND created_at>=? AND created_at<? GROUP BY m", [$start, $to]);
    $months = [];
    for ($i = 5; $i >= 0; $i--) { $k = date('Y-m', strtotime($from.' -'.$i.' months')); $months[$k] = (int) ($trend[$k] ?? 0); }
    $byUser = DB::all("SELECT a.user_id, u.name, COUNT(*) AS requests, SUM(CASE WHEN a.status='ok' THEN a.input_tokens+a.output_tokens ELSE 0 END) AS tokens, SUM(a.status='error') AS errors, SUM(a.status='blocked') AS blocked FROM ai_usage a LEFT JOIN users u ON u.id=a.user_id WHERE a.created_at>=? AND a.created_at<? GROUP BY a.user_id, u.name ORDER BY tokens DESC LIMIT 50", $p);
    $byFeature = DB::all("SELECT feature, COUNT(*) AS requests, SUM(CASE WHEN status='ok' THEN input_tokens+output_tokens ELSE 0 END) AS tokens, SUM(status='error') AS errors, SUM(status='blocked') AS blocked FROM ai_usage WHERE $w GROUP BY feature ORDER BY tokens DESC", $p);
    $byModel = DB::all("SELECT provider, model, COUNT(*) AS requests, SUM(input_tokens) AS input, SUM(output_tokens) AS output FROM ai_usage WHERE $w AND status='ok' GROUP BY provider, model ORDER BY requests DESC", $p);
    $errors = DB::all("SELECT a.created_at, a.feature, a.provider, a.model, a.status, a.error, u.name FROM ai_usage a LEFT JOIN users u ON u.id=a.user_id WHERE a.created_at>=? AND a.created_at<? AND a.status<>'ok' ORDER BY a.id DESC LIMIT 25", $p);
    $budget = Usage::budget();
    return $this->view('ai::usage', ['title' => 'AI usage', 'month' => $m, 'tot' => $tot, 'months' => $months, 'byUser' => $byUser, 'byFeature' => $byFeature, 'byModel' => $byModel, 'errors' => $errors, 'budget' => $budget, 'styles' => ['css/ai.css']]); } }
