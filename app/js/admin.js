/**
 * SPDX-FileCopyrightText: 2026 Michal Jonik
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Admin settings: save the service URL and token, show indexing progress. Plain script,
 * no build step.
 */
(function () {
	'use strict'

	const REFRESH_MS = 10000

	function init() {
		const root = document.getElementById('sightline-admin')
		if (!root) {
			return
		}
		const urls = JSON.parse(root.dataset.urls)
		const strings = JSON.parse(root.dataset.strings)
		const format = (s, values) => s.replace(/\{(\w+)\}/g, (match, key) => key in values ? values[key] : match)
		const number = (n) => n.toLocaleString()

		async function request(method, url, body) {
			const response = await fetch(url, {
				method,
				headers: {
					'Content-Type': 'application/json',
					requesttoken: document.head.dataset.requesttoken,
				},
				body: body === undefined ? undefined : JSON.stringify(body),
			})
			const data = await response.json()
			if (!response.ok) {
				throw new Error(data.error || data.message || response.statusText)
			}
			return data
		}

		function field(name, text, error) {
			const cell = root.querySelector('[data-field="' + name + '"]')
			cell.textContent = text
			cell.classList.toggle('error', Boolean(error))
		}

		function message(id, text, error) {
			const el = document.getElementById(id)
			el.textContent = text
			el.classList.toggle('error', Boolean(error))
		}

		function render(status) {
			const service = status.service
			field('service', service.ok
				? format(strings.serviceOk, { model: service.model, count: number(service.count) })
				: format(strings.serviceDown, { error: service.error }), !service.ok)
			field('indexed', number(status.files.indexed))
			// During a model change the previous model answers queries until re-encoding ends.
			const switching = service.ok && status.search_model && status.search_model !== service.model
			field('outdated', switching
				? format(strings.switching, { count: number(status.files.outdated), model: status.search_model })
				: number(status.files.outdated))
			field('failed', number(status.files.failed))
			field('queue', number(status.queue))
			const pending = status.pending
			field('pending', pending.crawls + pending.sweeps > 0
				? format(strings.running, { crawls: number(pending.crawls), sweeps: number(pending.sweeps) })
				: strings.idle)
			field('last', status.last_reconcile > 0
				? new Date(status.last_reconcile * 1000).toLocaleString()
				: strings.never)
		}

		async function refresh() {
			try {
				render(await request('GET', urls.status))
			} catch (e) {
				field('service', e.message, true)
			}
		}

		document.getElementById('sightline-form').addEventListener('submit', async (event) => {
			event.preventDefault()
			const form = event.target
			try {
				render(await request('PUT', urls.settings, { url: form.url.value, token: form.token.value }))
				form.token.value = ''
				message('sightline-form-msg', strings.saved)
			} catch (e) {
				message('sightline-form-msg', strings.saveFailed + ': ' + e.message, true)
			}
		})

		document.getElementById('sightline-reconcile').addEventListener('click', async () => {
			try {
				await request('POST', urls.reconcile)
				message('sightline-reconcile-msg', strings.scheduled)
				refresh()
			} catch (e) {
				message('sightline-reconcile-msg', e.message, true)
			}
		})

		refresh()
		setInterval(refresh, REFRESH_MS)
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init)
	} else {
		init()
	}
})()
