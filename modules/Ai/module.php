<?php
return [
  'key' => 'ai', 'name' => 'AI Assistant', 'icon' => 'sparkles', 'order' => 60, 'version' => '1.0.0',
  'description' => 'OpenAI, Anthropic Claude & OpenRouter powered summaries, drafts, suggestions, reports and natural-language search with human approval.',
  'permissions' => [
    'ai.use' => ['label' => 'Use AI assistant tools', 'roles' => ['manager', 'hr']],
    'ai.search' => ['label' => 'Natural-language search', 'roles' => ['manager', 'hr', 'employee']],
    'ai.usage.view' => ['label' => 'View AI usage & costs', 'roles' => []],
  ],
  'nav' => [
    ['label' => 'AI Assistant', 'icon' => 'sparkles', 'url' => '/ai', 'perm' => ['ai.use', 'ai.search'], 'group' => 'Intelligence', 'order' => 1],
  ],
];
