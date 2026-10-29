<?php
use Modules\Ai\Controllers\{ChatController, SettingsController, UsageController};

return [
  'key' => 'ai', 'name' => 'AI Assistant', 'icon' => 'sparkles', 'order' => 60, 'version' => '2.0.0',
  'description' => 'Goraiya, a chat assistant in a right-hand drawer that answers from your workspace data with role-based access. Works with OpenAI, Claude, OpenRouter, Gemini, Groq, Mistral, DeepSeek, Ollama or any OpenAI-compatible model.',
  'permissions' => [
    'ai.use' => ['label' => 'Chat with the AI assistant (answers stay within the user\'s own access)', 'roles' => ['manager', 'hr', 'employee']],
    'ai.usage.view' => ['label' => 'View AI usage & costs', 'roles' => []],
  ],
  'nav' => [
    ['label' => 'AI Usage', 'icon' => 'cpu', 'color' => '#F0508A', 'url' => '/ai/usage', 'perm' => 'ai.usage.view', 'section' => 'system', 'order' => 6],
  ],
  'routes' => function (Core\Router $r): void {
    $sa = ['perm' => '__super_admin'];
    $r->get('/ai', [ChatController::class, 'index'], 'ai.use');
    $r->post('/ai/chat', [ChatController::class, 'chat'], ['perm' => 'ai.use', 'throttle' => [20, 60]]);
    $r->get('/ai/conversations', [ChatController::class, 'conversations'], 'ai.use');
    $r->get('/ai/conversations/{id}', [ChatController::class, 'show'], 'ai.use');
    $r->post('/ai/conversations/{id}/delete', [ChatController::class, 'destroy'], 'ai.use');
    $r->get('/ai/usage', [UsageController::class, 'index'], 'ai.usage.view');
    $r->get('/ai/settings', [SettingsController::class, 'index'], $sa);
    $r->post('/ai/settings', [SettingsController::class, 'save'], $sa);
    $r->post('/ai/settings/toggle', [SettingsController::class, 'toggle'], $sa);
    $r->post('/ai/models', [SettingsController::class, 'store'], $sa);
    $r->post('/ai/models/{id}', [SettingsController::class, 'update'], $sa);
    $r->post('/ai/models/{id}/default', [SettingsController::class, 'makeDefault'], $sa);
    $r->post('/ai/models/{id}/delete', [SettingsController::class, 'destroy'], $sa);
    $r->post('/ai/models/{id}/test', [SettingsController::class, 'test'], $sa + ['throttle' => [10, 60]]);
  },
  'global_scripts' => ['js/ai.js' => 'ai.use'],
  'global_styles' => ['css/ai.css' => 'ai.use'],
  'topbar' => static fn (array $user): string => Modules\Ai\Services\Usage::enabled() && Core\Gate::allows('ai.use', $user) ? Core\View::partial('ai::topbar', ['u' => $user]) : '',
  'body_end' => static fn (array $user): string => Modules\Ai\Services\Usage::enabled() && Core\Gate::allows('ai.use', $user) ? Core\View::partial('ai::drawer', ['u' => $user]) : '',
];
