<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Michal Jonik
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Sightline\BackgroundJob;

use OCP\IAppConfig;

/**
 * How long one background job run may take. With system cron a run is its own process;
 * in AJAX or webcron mode it runs inside a page request, so keep it short.
 */
final class Budget {
	private const NON_CRON_SECONDS = 15;

	public static function seconds(IAppConfig $appConfig, int $cronSeconds): int {
		return $appConfig->getValueString('core', 'backgroundjobs_mode', 'ajax') === 'cron'
			? $cronSeconds
			: min($cronSeconds, self::NON_CRON_SECONDS);
	}
}
