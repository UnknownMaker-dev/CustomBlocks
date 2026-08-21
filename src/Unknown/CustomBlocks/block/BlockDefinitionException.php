<?php

declare(strict_types=1);

namespace Unknown\CustomBlocks\block;

use RuntimeException;
use Throwable;

/** Lançada quando uma entrada do blocks.yml não pode ser transformada em um bloco válido. */
final class BlockDefinitionException extends RuntimeException {

	public function __construct(private readonly string $identifier, string $reason, ?Throwable $previous = null) {
		parent::__construct("Bloco '$identifier': $reason", 0, $previous);
	}

	public function getIdentifier(): string {
		return $this->identifier;
	}
}
