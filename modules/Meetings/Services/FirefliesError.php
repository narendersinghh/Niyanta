<?php
namespace Modules\Meetings\Services;

/** Fireflies API failure; kind is rate (429, back off), auth (bad key), network or error. */
final class FirefliesError extends \RuntimeException {
  public function __construct(string $message, public readonly string $kind = 'error') { parent::__construct($message); } }
