# Production N305: indexing around the clock, and search while it runs

Date: 2026-10-30. Same machine and setup as the
[2026-10-28 check](2026-10-28-production-n305.md): Intel N305, Nextcloud 35.0.2, Imaginary,
Camera RAW Previews 1.1.4, XLM-R B/32 (512 dims), `sightline-embed` limited to 4 CPUs and
3 GiB with `SIGHTLINE_THREADS=4`. The app was updated to the commit that makes `IndexJob`
time-sensitive. The index held 130,263 vectors.

## Indexing outside the maintenance window

The instance sets `maintenance_window_start` to 01:00 UTC. After the update, `IndexJob` ran at
21:35, 21:45, 21:55 and 22:00 UTC, each time for its full 240 s budget. One cron run (21:40)
did not start it, so runs came every 5 to 10 minutes.

- Within a run: about 4.6 photos per 10 s, about 110 per run, so 2.2 s per photo. Most queued
  files at this point were RAW files on one storage. The share of that time spent generating
  previews was not measured.
- Per hour: 320 photos from 21:35 to 21:59 (three runs) and 112 in the 22:00 run. During the
  maintenance window on the two nights before, the job indexed 538 to 1,222 photos an hour.
- At 110 photos every 5 to 10 minutes, the 112,472 queued files take about 4 to 7 days,
  instead of about 25 nights at night-only rates.

## Search latency while indexing runs

Method as on 2026-10-28: `POST /search` from the Nextcloud container, 20 queries (10 English,
10 Polish) × 3 runs, 50 results, after two warm-up requests. The "indexing" set started 10 s
after an `IndexJob` run began; the "idle" set ran after that run's 240 s had ended. Times in
ms, p50 / p95:

| Scenario | Set | Text encoding | Scan | Wall clock | Slowest |
|---|---|---|---|---|---|
| All 7 storages | idle | 91 / 100 | 129 / 140 | 222 / 239 | 242 |
| All 7 storages | indexing | 92 / 563 | 131 / 759 | 226 / 1,293 | 1,372 |
| One storage | idle | 91 / 97 | 59 / 65 | 154 / 162 | 170 |
| One storage | indexing | 92 / 469 | 61 / 396 | 155 / 825 | 1,224 |

- The median does not change; the tail does. Over all storages, 18 of 60 requests took more
  than 300 ms while indexing ran (12 more than 600 ms), against none in the idle set.
- Both query encoding and the scan slow down, not only the scan. The store lock is held for a
  few milliseconds per upsert, so the likely cause is CPU: image encoding for indexing and
  text encoding for search each use 4 ONNX Runtime threads inside a 4-CPU limit. The
  container ran at 392–412 % CPU during both sets. A follow-up the next day
  ([2026-10-31](2026-10-31-cpu-while-indexing-n305.md)) confirmed it: the container was
  throttled by its CPU limit while the host had idle threads.
- Indexing slowed by about a quarter while the searches ran: 3.4 photos per 10 s against 4.6
  in the rest of the same run. That was 43 s of back-to-back searches, far more than people
  searching would cause.
- Memory: 742 to 745 MB RSS, well under the 3 GiB limit.
