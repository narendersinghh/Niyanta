<?php
/* Niyu chat drawer, rendered before </body> for users with ai.use while the assistant is on ($u = current user). */
use Modules\Ai\Services\Assistant;
[$ready, $why] = Assistant::status();
$admin = ($u['role_slug'] ?? '') === 'super_admin';
$first = explode(' ', trim((string) $u['name']))[0];
?>
<div class="ai-scrim" data-ai-scrim hidden></div>
<aside id="ai-drawer" class="ai-drawer" role="dialog" aria-modal="false" aria-labelledby="ai-title" hidden data-ai data-ready="<?= $ready ? '1' : '0' ?>" data-suggest="<?= e(json_encode(Assistant::suggestions($u), JSON_UNESCAPED_UNICODE)) ?>">
  <header class="ai-head">
    <span class="ai-ava lg"><img src="<?= e(asset('img/logo.svg')) ?>" alt=""></span>
    <div class="grow"><h2 id="ai-title">Niyu</h2><small class="ai-sub" data-ai-sub><span class="ai-live"></span>AI assistant</small></div>
    <button type="button" class="ai-ib" data-ai-new title="New chat" aria-label="New chat"><?= icon('plus', 'sm') ?></button>
    <div class="ai-hist">
      <button type="button" class="ai-ib" data-ai-hist aria-haspopup="true" aria-expanded="false" title="Recent chats" aria-label="Recent chats"><?= icon('history', 'sm') ?></button>
      <div class="ai-hist-menu" data-ai-hist-menu hidden><div class="ai-hist-title">Recent chats</div><div data-ai-hist-list></div></div>
    </div>
    <button type="button" class="ai-ib" data-ai-close title="Close (Esc)" aria-label="Close assistant"><?= icon('x', 'sm') ?></button>
  </header>
  <div class="ai-body" data-ai-scroll>
    <div class="ai-welcome" data-ai-welcome>
      <span class="ai-ava xl"><img src="<?= e(asset('img/logo.svg')) ?>" alt=""></span>
      <h3>Hi <?= e($first) ?>, I'm Niyu</h3>
      <?php if ($ready): ?>
      <p>Ask me about your work in plain words. I only look at what you are allowed to see in <?= e(company_name()) ?>.</p>
      <div class="ai-chips" data-ai-chips></div>
      <?php else: ?>
      <div class="ai-notice"><?= icon('info', 'sm') ?><div><b>I'm not set up yet.</b><br><?php if ($admin): ?>Add an AI model (ChatGPT, Claude, Gemini, OpenRouter and more) to start chatting.<?php else: ?>An administrator needs to connect an AI model first. Please check back later.<?php endif ?></div></div>
      <?php if ($admin): ?><a class="btn primary sm" href="<?= e(url('/ai/settings')) ?>"><?= icon('settings', 'sm') ?> Open AI settings</a><?php endif ?>
      <?php endif ?>
    </div>
    <div class="ai-msgs" data-ai-msgs aria-live="polite"></div>
    <div class="ai-typing" data-ai-typing hidden><span class="ai-ava sm"><img src="<?= e(asset('img/logo.svg')) ?>" alt=""></span><span class="ai-dots" aria-label="Niyu is thinking"><i></i><i></i><i></i></span><small data-ai-typing-text>Looking it up…</small></div>
  </div>
  <form class="ai-form" data-ai-form>
    <div class="ai-input">
      <textarea data-ai-input rows="1" maxlength="2000" placeholder="<?= $ready ? 'Ask Niyu anything…' : 'The assistant is not available yet' ?>" aria-label="Message Niyu"<?= $ready ? '' : ' disabled' ?>></textarea>
      <button type="submit" class="ai-send" data-ai-send aria-label="Send" title="Send (Enter)"<?= $ready ? '' : ' disabled' ?>><?= icon('send', 'sm') ?></button>
    </div>
    <p class="ai-foot"><span><kbd>Enter</kbd> to send, <kbd>Shift</kbd>+<kbd>Enter</kbd> for a new line</span><span>Niyu can make mistakes.</span></p>
  </form>
</aside>
