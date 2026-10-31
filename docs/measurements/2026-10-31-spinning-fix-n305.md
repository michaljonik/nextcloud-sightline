# Production N305: search and indexing after the spinning change

Date: 2026-10-31, 13:24–13:59 UTC. Same day and setup as the
[morning check](2026-10-31-cpu-while-indexing-n305.md): Intel N305 (8 threads), Nextcloud
35.0.2, Imaginary, Camera RAW Previews 1.1.4, XLM-R B/32, `sightline-embed` limited to 4 CPUs
(`cpu.max` 400000/100000) with `SIGHTLINE_THREADS=4`. The service now ran `embed/` from
`ec46580` (onnxruntime 1.31.1), in which the image encoder's threads no longer spin between
runs ([M4 reproduction](2026-10-31-search-while-indexing-m4.md)); the text encoder's still do.
The index held 139,992 to 140,243 vectors during the runs (morning: 137,784). `IndexJob` ran
every 5 minutes for its full 240 s budget.

## Method

- Same scripts as in the morning: `POST /search` from the Nextcloud container, 20 queries × 3,
  50 results, after two warm-up requests, over all 7 storages and over one storage. Phases:
  search while `IndexJob` runs, indexing with no searches (about 40 s), and search in the gap
  between two runs. Sampled per phase: the service container's cgroup `cpu.stat`, `docker
  stats`, the top 10 processes from `top`, and host CPU from `/proc/stat`.
- Two runs at 4 CPUs, both with a valid search-only set, and one run at 6 CPUs
  (`docker update --cpus 6`, then back to 4).
- The search-while-indexing phase took 27–29 s for the same 120 requests (morning: 39–41 s),
  because the requests were faster.
- Added: one full `IndexJob` run with no searches, with the service cgroup sampled every 10 s,
  and a 60 s `cpu.stat` sample of the Nextcloud cron container during a run.
- All 720 requests returned 50 results.

## Latency

Times in ms, p50 / p95. The morning values are in the
[earlier note](2026-10-31-cpu-while-indexing-n305.md#latency).

| Scenario | Phase | Text encoding | Scan | Wall clock | Slowest | > 300 ms |
|---|---|---|---|---|---|---|
| All 7 storages | search while indexing, run 1 | 95 / 114 | 146 / 210 | 245 / 352 | 454 | 6/60 |
| All 7 storages | search while indexing, run 2 | 97 / 114 | 148 / 221 | 249 / 388 | 421 | 7/60 |
| All 7 storages | search only, run 1 | 93 / 101 | 143 / 158 | 238 / 258 | 260 | 0/60 |
| All 7 storages | search only, run 2 | 91 / 100 | 141 / 150 | 235 / 249 | 412 | 1/60 |
| All 7 storages | search while indexing, 6 CPUs | 96 / 105 | 149 / 164 | 247 / 274 | 350 | 2/60 |
| All 7 storages | search only, 6 CPUs | 91 / 100 | 142 / 152 | 238 / 256 | 261 | 0/60 |
| One storage | search while indexing, run 1 | 96 / 112 | 70 / 161 | 169 / 335 | 372 | 5/60 |
| One storage | search while indexing, run 2 | 96 / 131 | 70 / 82 | 169 / 236 | 362 | 3/60 |
| One storage | search only, run 1 | 92 / 100 | 66 / 76 | 161 / 176 | 195 | 0/60 |
| One storage | search only, run 2 | 91 / 100 | 66 / 82 | 160 / 183 | 210 | 0/60 |
| One storage | search while indexing, 6 CPUs | 95 / 104 | 71 / 79 | 170 / 183 | 246 | 0/60 |
| One storage | search only, 6 CPUs | 91 / 98 | 66 / 80 | 161 / 174 | 185 | 0/60 |

While indexing ran, the p95 over all storages fell from 1,001–1,083 ms in the morning to
352–388 ms, and the slowest request from 1,152–1,319 ms to 421–454 ms. No request took more
than 600 ms; in the morning each search-while-indexing set had 7 to 9. Search alone is
unchanged.

## CPU limit of the service container

| Phase | Periods throttled | Time throttled | CPU used (average) |
|---|---|---|---|
| Search while indexing, run 1 | 119 of 278 (43 %) | 5.47 s | 382 % |
| Search while indexing, run 2 | 135 of 275 (49 %) | 4.77 s | 391 % |
| Indexing only, run 1 | 0 of 137 | 0 s | 11 % |
| Indexing only, run 2 | 0 of 136 | 0 s | 10 % |
| Search only, run 1 | 85 of 248 (34 %) | 0.07 s | 396 % |
| Search only, run 2 | 90 of 247 (36 %) | 0.42 s | 394 % |
| Full `IndexJob` run, no searches (250 s) | 0 of 887 | 0 s | 9 % |
| Search while indexing, 6 CPUs | 4 of 261 (1.5 %) | 0.11 s | 394 % |
| Indexing only, 6 CPUs | 0 of 136 | 0 s | 10 % |
| Search only, 6 CPUs | 0 of 248 | 0 s | 393 % |

In the morning, search while indexing was throttled in 68 % of periods for 48–50 s, and
indexing alone used 76 % CPU on average.

The full run with no searches used 21.35 CPU seconds for 79 photos: 0.27 CPU seconds per photo.
The indexing-only windows give 0.26–0.28. In the morning it was about 2.2.

## Host and the Nextcloud cron container

| Phase | Host CPU busy (8 threads) | Cron container (`docker stats`, avg) |
|---|---|---|
| Search while indexing | 62–64 % | 35–38 % |
| Indexing only | 13–16 % | 38–39 % |
| Search only | 55–57 % | job finished |
| Full run, no searches | 10 % | not sampled |

- Preview generation runs in PHP in the cron container. Imaginary stayed at 0 %, and no
  exiftool or ImageMagick process appeared among the top 10.
- The cron container is limited to 0.4 CPUs (`cpu.max` 40000/100000). Sampled for 60 s during
  a run with no searches, it was throttled in 567 of 600 periods (94 %) and used 23.5 CPU
  seconds (39 %), its whole quota.

## Indexing throughput

| Run | Searches during the run | CPU limit | Photos |
|---|---|---|---|
| 13:30 | yes | 4 | 93 |
| 13:40 | no | 4 | 79 |
| 13:45 | yes | 4 | 79 |
| 13:55 | yes | 6 | 78 |

In the morning it was 89 and 81. Searches no longer slow indexing down visibly: 10 s bins
during searches held about 3 to 4 photos, like the rest of the run.

## Findings

- With the image encoder no longer spinning, the service spends about 8 times less CPU per
  photo (0.27 instead of about 2.2 CPU seconds), and the search tail while indexing runs
  drops from about 1 s to 0.24–0.39 s at the 95th percentile.
- Back-to-back searches alone still use the whole 4-CPU quota, because the text encoder's
  threads spin. While indexing runs the container is therefore still throttled in about half
  of the periods, but for about 5 s instead of 50 s. With 6 CPUs the throttling is gone and
  the p95 while indexing equals that of search alone; average use stays at about 394 %.
- Indexing did not get faster: about 80 to 90 photos per 240 s run, about 3 s per photo. The
  limit is now preview generation in PHP, in a cron container held to 0.4 CPUs. Not measured:
  whether this limit also explains why background runs were about 4 times slower per photo
  than `occ sightline:index` on 2026-10-25 (0.54 s), and how much a higher cron limit would
  gain.
