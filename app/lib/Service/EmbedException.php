<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Michal Jonik
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Sightline\Service;

/**
 * A failed call to sightline-embed. The code is the HTTP status, or 0 when the service
 * could not be reached.
 */
class EmbedException extends \RuntimeException {
	/** The service refused this input (e.g. an undecodable image); retrying will not help. */
	public function isRejected(): bool {
		return in_array($this->getCode(), [400, 413, 415, 422], true);
	}
}
