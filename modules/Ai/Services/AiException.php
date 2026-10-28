<?php
namespace Modules\Ai\Services;

/** Error with a user-safe message ($getMessage) and an internal detail that is only logged. */
final class AiException extends \RuntimeException {
  public function __construct(string $friendly, public readonly string $detail = '', public readonly string $status = 'error') { parent::__construct($friendly); } }
