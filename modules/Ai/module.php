<?php
use Modules\Ai\Controllers\{ActionController, AssistantController, SearchController, SettingsController, UsageController};

return [
  'key' => 'ai', 'labs' => true, 'name' => 'AI Assistant', 'icon' => 'sparkles', 'order' => 60, 'version' => '1.0.0',
  'description' => 'OpenAI, Anthropic Claude & OpenRouter powered summaries, drafts, suggestions, reports and natural-language search with human approval.',
  'permissions' => [
    'ai.use' => ['label' => 'Use AI assistant tools', 'roles' => ['manager', 'hr']],
    'ai.search' => ['label' => 'Natural-language search', 'roles' => ['manager', 'hr', 'employee']],
    'ai.usage.view' => ['label' => 'View AI usage & costs', 'roles' => []],
  ],
  'nav' => [
  ],
  'settings_view' => 'ai::settings',
  'routes' => function (Core\Router $r): void {
    $sa = ['perm' => '__super_admin'];
    $r->get('/ai', [AssistantController::class, 'index'], ['ai.use', 'ai.search']);
    $r->post('/ai/run/{tool}', [AssistantController::class, 'run'], ['perm' => 'ai.use', 'throttle' => [30, 60]]);
    $r->post('/ai/meeting/save', [AssistantController::class, 'saveMeeting'], 'ai.use');
    $r->post('/ai/reminder/queue', [AssistantController::class, 'queueReminder'], 'ai.use');
    $r->get('/ai/search', [SearchController::class, 'index'], ['perm' => 'ai.search', 'throttle' => [30, 60]]);
    $r->get('/ai/actions', [ActionController::class, 'index'], 'ai.use');
    $r->get('/ai/actions/{id}', [ActionController::class, 'show'], 'ai.use');
    $r->post('/ai/actions/{id}/approve', [ActionController::class, 'approve'], 'ai.use');
    $r->post('/ai/actions/{id}/reject', [ActionController::class, 'reject'], 'ai.use');
    $r->get('/ai/usage', [UsageController::class, 'index'], 'ai.usage.view');
    $r->get('/ai/settings', [SettingsController::class, 'index'], $sa);
    $r->post('/ai/settings', [SettingsController::class, 'save'], $sa);
    $r->post('/ai/settings/test', [SettingsController::class, 'test'], $sa + ['throttle' => [10, 60]]);
  },
  'widgets' => [
    ['zone' => 'side', 'order' => 40, 'perm' => 'ai.use', 'render' => static fn (array $user): string => Modules\Ai\Services\AiClient::enabled() ? Core\View::partial('ai::partials/widget', ['u' => $user]) : ''],
  ],
];
