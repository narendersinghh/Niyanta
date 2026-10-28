<?php
namespace Modules\Ai\Services;

/** A chat-completion backend. $messages = [['role' => 'system'|'user'|'assistant', 'content' => string], ...] */
interface ProviderInterface {
  /** @return array{text: string, input_tokens: int, output_tokens: int, model: string} */
  public function complete(array $messages, array $opts): array;
  public function name(): string;
  public function model(): string; }
