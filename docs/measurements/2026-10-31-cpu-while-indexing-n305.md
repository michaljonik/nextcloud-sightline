# Production N305: where the CPU goes while indexing runs

Date: 2026-10-31. Follow-up to the [2026-10-30 check](2026-10-30-search-while-indexing-n305.md)
and the [M4 reproduction](2026-10-31-search-while-indexing-m4.md). Same machine and setup:
Intel N305 (8 threads), Nextcloud 35.0.2, Imaginary, Camera RAW Previews 1.1.4, XLM-R B/32,
`sightline-embed` limited to 4 CPUs (`cpu.max` 400000/100000) with `SIGHTLINE_THREADS=4`.
The service ran the image from before the spinning change, so both encoders spin. The index
held 137,784 vectors. `IndexJob` ran every 5 minutes, each time for its full 240 s budget.

## Method

- Search benchmark as on 2026-10-30: `POST /search` from the Nextcloud container, 20 queries
  × 3, 50 results, after two warm-up requests. Over all 7 storages and over one storage.
- Phases, each about 40 s: search while `IndexJob` runs, indexing with no searches, and
  search in the ~55 s gap between two runs. Two runs; in the first, the idle set overlapped
  the next job and is left out.
- Sampled during each phase: the service container's cgroup `cpu.stat` (deltas over the
  phase), `docker stats` for all containers, the top 10 processes from `top`, and host CPU
  from `/proc/stat`.

## Latency

Times in ms, p50 / p95:

| Scenario | Phase | Text encoding | Scan | Wall clock | Slowest | > 300 ms |
|---|---|---|---|---|---|---|
| All 7 storages | search while indexing, run 1 | 97 / 486 | 146 / 646 | 248 / 1,083 | 1,319 | 17/60 |
| All 7 storages | search while indexing, run 2 | 95 / 448 | 142 / 546 | 242 / 1,001 | 1,152 | 17/60 |
| All 7 storages | search only | 94 / 103 | 141 / 151 | 239 / 252 | 266 | 0/60 |
| One storage | search while indexing, run 1 | 96 / 468 | 69 / 334 | 167 / 797 | 1,005 | 13/60 |
| One storage | search while indexing, run 2 | 93 / 520 | 66 / 340 | 162 / 927 | 1,009 | 12/60 |
| One storage | search only | 94 / 100 | 65 / 75 | 162 / 175 | 270 | 0/60 |

The pattern of 2026-10-30 repeats: the median stays, the tail grows in both text encoding
and the scan.

## CPU limit of the service container

| Phase | Periods throttled | Time throttled | CPU used (average) |
|---|---|---|---|
| Search while indexing, run 1 | 275 of 405 (68 %) | 50.0 s | 392 % |
| Search while indexing, run 2 | 261 of 383 (68 %) | 48.2 s | 393 % |
| Indexing only | 0 of 201 | 0 s | 76 % (207 % at peak) |
| Search only | 88 of 248 (35 %) | 0.38 s | 395 % |

Time throttled is summed over CPUs, so it can exceed the phase's duration. When a cgroup has
used its quota for a 100 ms period, it waits for the next one; a request that meets several
such waits gains hundreds of milliseconds.

## Host and other processes

| Phase | Host CPU busy (8 threads) | Nextcloud cron container | Imaginary |
|---|---|---|---|
| Search while indexing | 61 % | 33–37 % | 0 % |
| Indexing only | 20 % | 40 % | 0 % |
| Search only | 56 % | job finished | 0 % |

- Preview generation runs in PHP in the cron container and takes about a third of one core.
  No exiftool or ImageMagick process appeared among the top 10 in any sample. All other
  containers stayed under 10 % each, apart from one short spike.
- Indexing did 89 and 81 photos per run, about 2.8 s per photo. While searches ran it slowed
  from about 3.5–3.8 to 2.75 photos per 10 s, as on 2026-10-30.

## Findings

- The tail comes from the service container's CPU limit, not from a busy host. Search alone
  touches the limit only briefly, indexing alone stays under it, and the two together keep
  the container throttled in two of three periods while about 3 of the host's 8 threads are
  idle. Preview generation does not compete for CPU in a way that explains the tail.
- Indexing alone used 30.3 CPU seconds in 40 s for about 14 photos, so about 2.2 CPU seconds
  per photo. One image encode takes about 0.2 s on one N305 thread
  ([2026-10-25](2026-10-25-n305-family-library.md)). The difference fits the image encoder's
  threads spinning between runs, which the M4 reproduction showed and which is now turned
  off. Not yet measured on the N305 with that change.
- Back-to-back search alone also uses the whole quota, most likely because the text
  encoder's threads spin during the scan. That spinning stays on: turning it off slowed
  idle queries on the M4.
- Not measured: the service with the spinning change, and with a higher CPU limit.
