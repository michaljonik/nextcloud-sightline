# Search while indexing, reproduced on the M4

Date: 2026-10-31. Follow-up to the [production check of 2026-10-30](2026-10-30-search-while-indexing-n305.md),
where the search p95 rose from 239 ms to 1,293 ms while `IndexJob` ran. The question here: how
much of that comes from contention inside `sightline-embed`, and what reduces it.

## Setup

- MacBook Air M4, Docker Desktop (5-CPU VM). `sightline-embed` from this repository in a
  container limited to 4 CPUs and 3 GiB, `SIGHTLINE_THREADS=4`, XLM-R B/32 (512 dims), ONNX
  Runtime 1.31. Same limits as production.
- Store: 130,263 random unit vectors in 7 storages, as many as production held.
- Search: 20 queries (10 English, 10 Polish) × 6, back to back, limit 50, all 7 storages,
  after 20 warm-up requests. 120 requests per set; times in ms.
- Indexing: one client sends 1024 px JPEG previews to `PUT /files/{id}` in a loop. With a
  2 s pause after each photo, the service sees about the load it gets on production (one
  photo every ~2.2 s, almost all of which is preview generation in Nextcloud). With no pause,
  it indexes as fast as it can, which shows the effect of contention more clearly.
- Each variant ran in a fresh container. Runs vary by about ±30 ms at p95.

## Results

Indexing with no pause:

| Variant | Wall p50 / p95 | Text p95 | Scan p95 | > 300 ms | Photos / s |
|---|---|---|---|---|---|
| Before (two runs) | 316 / 386, 357 / 465 | 242, 276 | 122, 139 | 82, 107 | 8.4, 7.3 |
| Image encoder on 2 threads | 259 / 328 | 186 | 107 | 26 | 7.5 |
| Image encoder on 1 thread | 268 / 313 | 104 | 110 | 11 | 7.0 |
| No spinning in either encoder | 226 / 264 | 111 | 100 | 4 | 12.7 |
| No spinning in the image encoder | 238 / 268 | 129 | 106 | 0 | 10.9 |
| No spinning in the image encoder, run again | 263 / 290 | 135 | 114 | 2 | 10.1 |

With no indexing, searches took 121–167 ms p50 across all runs. Query encoding took 56–69 ms
p50 in the four runs with spinning off in both encoders, against 41–48 ms in all other runs
but one (57 ms).

Indexing with a 2 s pause, before and after turning spinning off in the image encoder:

| Variant | Idle p50 / p95 | Indexing p50 / p95 | Slowest | > 300 ms |
|---|---|---|---|---|
| Before | 122 / 151 | 126 / 179 | 344 | 2 |
| No spinning in the image encoder | 136 / 146 | 136 / 167 | 210 | 0 |

## Findings

- ONNX Runtime's worker threads spin for a while after each run, waiting for more work. With
  two sessions of 4 threads each inside a 4-CPU limit, the image encoder's spinning threads
  take CPU from a search running at the same time. Turning spinning off for the image
  encoder only (`session.intra_op.allow_spinning = 0`) cut searches over 300 ms under full
  indexing load from 82 and 107 to 0 and 2 of 120, left idle search as it was, and indexed
  10.1–10.9 photos/s against 7.3–8.4.
- Fewer image-encoder threads also helped, but less, and indexing got slower.
- At the production indexing rate, the effect inside the service is small here: p95 rose by
  about 30 ms, within the noise between runs. On production it rose by about 1,050 ms. The
  N305 encodes an image about 6 times slower on one thread (204 ms against 34 ms), so the
  same load weighs more there. A check on production the same day
  ([2026-10-31](2026-10-31-cpu-while-indexing-n305.md)) found the service container
  throttled by its CPU limit while the host had idle threads, which points to the same cause.
- Moving image encoding off the event loop (the upload handler is `async` and encodes in
  place) made the full-load case worse: 38 requests over 300 ms. Not pursued.
