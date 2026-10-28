<?php
namespace Core;

final class ValidationException extends \RuntimeException {
  public function __construct(public readonly array $errors) { parent::__construct('Validation failed'); } }
