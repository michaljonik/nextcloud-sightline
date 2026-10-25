<?php
/**
 * SPDX-FileCopyrightText: 2026 Michal Jonik
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
?>
<div id="sightline-admin" class="section"
	data-urls="<?php p(json_encode($_['urls'])); ?>"
	data-strings="<?php p(json_encode($_['strings'])); ?>">
	<h2>Sightline</h2>
	<p class="settings-hint">
		<?php p($l->t('Semantic photo search. Photos are encoded by sightline-embed, a separate service on your network; Nextcloud sends it preview images and checks permissions on every result.')); ?>
	</p>

	<h3><?php p($l->t('Embedding service')); ?></h3>
	<form id="sightline-form">
		<p>
			<label for="sightline-url"><?php p($l->t('Service URL')); ?></label>
			<input id="sightline-url" name="url" type="url" required value="<?php p($_['embedUrl']); ?>" placeholder="http://localhost:8765">
		</p>
		<p>
			<label for="sightline-token"><?php p($l->t('Token')); ?></label>
			<input id="sightline-token" name="token" type="password" autocomplete="new-password"
				placeholder="<?php p($_['hasToken'] ? $l->t('Stored; leave empty to keep') : $l->t('SIGHTLINE_TOKEN of the service')); ?>">
		</p>
		<p>
			<button type="submit" class="button primary"><?php p($l->t('Save and test')); ?></button>
			<span id="sightline-form-msg" class="sightline-msg" role="status"></span>
		</p>
	</form>

	<h3><?php p($l->t('Index')); ?></h3>
	<table class="sightline-status">
		<tr><th><?php p($l->t('Service')); ?></th><td data-field="service">…</td></tr>
		<tr><th><?php p($l->t('Indexed photos')); ?></th><td data-field="indexed">…</td></tr>
		<tr><th><?php p($l->t('Indexed with an older model')); ?></th><td data-field="outdated">…</td></tr>
		<tr><th><?php p($l->t('Failed (no preview or not decodable)')); ?></th><td data-field="failed">…</td></tr>
		<tr><th><?php p($l->t('Queued')); ?></th><td data-field="queue">…</td></tr>
		<tr><th><?php p($l->t('Reconciliation')); ?></th><td data-field="pending">…</td></tr>
		<tr><th><?php p($l->t('Last reconciliation')); ?></th><td data-field="last">…</td></tr>
	</table>
	<p>
		<button id="sightline-reconcile" type="button" class="button"><?php p($l->t('Scan for changes now')); ?></button>
		<span id="sightline-reconcile-msg" class="sightline-msg" role="status"></span>
	</p>
	<p class="settings-hint">
		<?php p($l->t('Indexing runs as background jobs and needs system cron. A daily scan catches changes that file events miss; external storage is only noticed after it has been scanned by Nextcloud.')); ?>
	</p>
</div>
