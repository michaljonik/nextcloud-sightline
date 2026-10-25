<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Michal Jonik
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OC\Hooks;

// Signature copied from server lib/private/Hooks/Emitter.php; OCP\Files\IRootFolder extends it.
interface Emitter {
	public function listen($scope, $method, callable $callback);

	public function removeListener($scope = null, $method = null, ?callable $callback = null);
}
