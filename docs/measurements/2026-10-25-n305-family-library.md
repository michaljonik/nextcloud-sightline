# Target hardware: Intel N305 with a private photo library

Date: 2026-10-24/25. Machine: the N305 home server Sightline is built for. Follows
[the M4 model comparison](2026-10-24-model-comparison-m4.md), with the same `spike.py` and
the same English and Polish queries, now on a sample of a real family library instead of
stock photos.

Questions: how fast are XLM-R B/32 and SigLIP 2 B/16 on the target CPU, does OpenVINO help,
and is the JPEG embedded in RAW files good enough to index from?

Photos, thumbnails and embeddings stayed on the server; this note has numbers only.

## Setup

- Intel Core i3-N305 (8 E-cores, 0.8–3.8 GHz), 32 GB DDR5 in one module (the N305 has a
  single memory channel), Ubuntu 26.04, Python 3.12, ONNX Runtime 1.31 (CPU execution
  provider), Pillow 13, pillow-heif 1.8.
- The server was running its normal services (Nextcloud and others) during the tests. Every
  run used `nice -n 19`, one job at a time; the 1-minute load average was 1.2–1.8 at the
  start of the timed runs.
- Library: about 117,000 photo files. By count 54 % JPEG, 42 % NEF, 3 % DNG, under 1 % each
  TIFF, ARW, PNG and HEIC (25 HEIC files in total).
- Sample: 400 originals chosen with EXIF from random candidates: 100 phone JPEGs (27 phone
  models), 25 HEIC, 60 camera JPEGs (about 45 camera models, 2002–2020), 40 screenshots
  (JPEG and PNG), 40 frames from 9 burst series, 60 NEF, 60 DNG, 15 ARW.

## Decode and downscale to 1024 px

`spike.py prepare`, one process, originals on the HDD array. Mean milliseconds per photo,
first read (cold page cache).

| Format | n | Megapixels decoded (median) | Mean, cold | Mean, warm |
|---|---|---|---|---|
| JPEG, phone and camera | 154 | 6.2–8.0 | 126–139 | 119–129 |
| JPEG, 12 MP phone | 6 | 12.2 | 198 | 183 |
| JPEG, 12 MP DSLR bursts | 40 | 12.2 | 188 | 179 |
| Screenshots (JPEG / PNG) | 25 / 15 | 2.6 / 0.2 | 48 / 29 | 38 / 25 |
| HEIC | 25 | 12.2 | 334 | 318 |
| NEF (embedded JPEG) | 60 | 24.0 | 382 | 355 |
| DNG (embedded JPEG) | 60 | 0.7 | 327 | 309 |
| ARW (embedded JPEG) | 15 | 2.1 | 306 | 284 |
| All 400 | | 7.0 | 216 | 203 |

Disk reads are a small share: cold and warm differ by about 6 %. For RAW files most of the
time is starting `exiftool` once per file (180–200 ms, twice for DNGs that lack
`JpgFromRaw`). A single long-lived `exiftool -stay_open` extracts the same JPEG in 12–21 ms
for NEF, 17–38 ms for DNG and about 50 ms for ARW.

## What RAW files carry

All 135 RAW files of the sample:

| Type | Source | n | Embedded JPEG |
|---|---|---|---|
| NEF | Nikon DSLRs | 60 | full sensor size (4288×2848 to 6016×4016) |
| DNG | Lightroom conversions (Nikon, Sony, Canon), DJI drones and action camera | 60 | preview with a 960–1024 px long edge |
| ARW | Sony mirrorless | 15 | 1616×1080 or 1920×1080 |

No embedded JPEG carries an orientation tag, while 22 of the 135 RAW files (16 %) are
portrait according to the RAW container. Read naively, portrait RAWs are indexed sideways.

To check whether the embedded JPEG represents the photo, each RAW file was also rendered
with LibRaw (half size, camera white balance) and both versions were embedded with the same
model. Cosine similarity:

| | XLM-R B/32 | SigLIP 2 B/16 |
|---|---|---|
| Embedded JPEG vs LibRaw render, median / 10th percentile / min | 0.957 / 0.837 / 0.705 | 0.977 / 0.916 / 0.821 |
| Portrait files only, XLM-R: as extracted → rotated by the RAW orientation (median) | 0.76–0.87 → 0.90–0.99 | |
| Reference: neighbouring frames of a burst, median / min | 0.956 / 0.743 | 0.961 / 0.876 |
| Reference: random pairs of photos, median / 99th percentile | 0.453 / 0.745 | 0.559 / 0.766 |

Once rotated, the embedded JPEG is as close to the LibRaw render as two frames of the same
burst are to each other. The remaining differences come from rendering (Lightroom edits
baked into DNG previews, camera picture styles), not from resolution. A LibRaw render as a
fallback costs 110–860 ms per file depending on the camera.

## Image encoder on the CPU

The same 60 photos at 1024 px for every run, batch size 1.

| Model | Threads | Encode median / p90 | Photos/s | Peak RSS |
|---|---|---|---|---|
| XLM-R B/32 | 1 | 204 / 208 ms | 4.8 | 0.66 GB |
| XLM-R B/32 | 8 | 49.6 / 52.9 ms | 18.1 | 0.69 GB |
| SigLIP 2 B/16-224 | 1 | 767 / 777 ms | 1.3 | 0.63 GB |
| SigLIP 2 B/16-224 | 8 | 190 / 205 ms | 5.3 | 0.68 GB |

SigLIP 2 B/16 costs 3.75× as much as XLM-R B/32 here (about 3.3× on the M4). Per thread the
N305 is about 6× slower than the M4. Eight threads give a 4.0–4.1× speed-up for about 7.5×
the CPU time.

## End to end on the 400 originals

Decode from the original, downscale, encode with 8 threads; then all English and Polish
queries. No failures with either model.

| | XLM-R B/32 | SigLIP 2 B/16 |
|---|---|---|
| 400 photos | 124 s, 3.2 photos/s | 175 s, 2.3 photos/s |
| Peak RSS while indexing | 1.12 GB | 1.11 GB |
| Text tower load | 2.1–3.2 s | 2.6–4.1 s |
| Peak RSS of the query process (text tower only) | 2.2 GB | 2.1 GB |
| Query encode, 8 threads, median EN / PL | 61 / 62 ms | 53 / 54 ms |
| Query encode, 1 thread, median EN | 293 ms | 241 ms |

The first query after loading was at most 5 ms slower than the median; the cost of a cold
start is loading the model (2–4 s and about 2 GB of memory), not the first inference.

## Retrieval quality on the family photos

Same method as on the M4: for each English and Polish query, the top 8 of both models were
pooled, shuffled and judged blind by the library's owner (model hidden). A Polish query
shares the judgements of its English original. 24 of the 29 queries had at least one
relevant photo in the sample (174 relevant marks in total); the other 5 are left out.

| Model | nP@8 EN | nP@8 PL | MRR EN | MRR PL | nP@8 mean |
|---|---|---|---|---|---|
| XLM-R B/32 | 0.728 | 0.654 | 0.872 | 0.802 | 0.691 |
| SigLIP 2 B/16-224 | 0.774 | 0.580 | 0.769 | 0.643 | 0.677 |
| For reference, M4 stock set: XLM-R B/32 | 0.882 | 0.854 | 0.899 | 0.899 | |
| For reference, M4 stock set: SigLIP 2 B/16 | 0.838 | 0.820 | 0.946 | 0.899 | |

Per query (nP@8), SigLIP 2 was better than XLM-R on 7 English queries and worse on 2
(15 equal); in Polish it was better on 4 and worse on 11 (9 equal).

- English: SigLIP 2 fills the top 8 slightly better and XLM-R puts the first hit higher.
  One query ("shallow depth-of-field", 6 relevant photos) scores 1.00 for XLM-R in English
  and 0.00 in the other three runs; without it, English nP@8 is 0.716 (XLM-R) vs 0.807
  (SigLIP 2). On family photos in English, SigLIP 2 is ahead, unlike on the stock set.
- Polish: XLM-R is ahead by 7 points nP@8 and 16 points MRR. SigLIP 2 loses 19 nP@8 points
  from English to Polish here (2 on the M4 stock set); XLM-R loses 7 (3 on the M4).
- Scores are lower than on the curated stock set: relevant photos are fewer and less
  obvious in a random family sample. With one judge, 24 queries and 1–17 relevant photos
  per query, differences of a few points are noise; the Polish gap, consistent across
  11 of 24 queries, is the one clear signal.
- RAW files indexed from their embedded JPEG were found by both models (NEF, DNG and ARW
  together: 53 relevant top-8 hits for XLM-R, 53 for SigLIP 2, English and Polish summed).

## OpenVINO

`onnxruntime-openvino` 1.24.1 with CPU fallback disabled, so a session that does not run
fully on OpenVINO fails instead of quietly timing the CPU provider. Same 60 photos;
embeddings matched the CPU provider (cosine 1.00000).

| Model | Threads | Encode median | vs ONNX Runtime CPU | Peak RSS |
|---|---|---|---|---|
| XLM-R B/32 | 8 | 40.9 ms | −18 % | 1.22 GB |
| XLM-R B/32 | 1 | 196 ms | −4 % | 1.22 GB |
| SigLIP 2 B/16 | 8 | 217 ms | +14 % | 1.27 GB |
| SigLIP 2 B/16 | 1 | 768 ms | ±0 % | 1.27 GB |

FP16 on the CPU was refused by the provider. The iGPU could not be tested: the server has
no Intel GPU compute runtime installed. With fallback allowed, a session that asks for the
GPU silently runs on the CPU, with only a log warning.

## Extrapolation to 200,000 photos

Arithmetic on the means above, library format mix, one process running continuously:

| | Per photo | 200k photos |
|---|---|---|
| Encode, XLM-R B/32, 1 / 8 threads | 204 / 50 ms | 11.3 / 2.8 h |
| Encode, SigLIP 2 B/16, 1 / 8 threads | 767 / 190 ms | 42.6 / 10.6 h |
| Measured end to end, 8 threads, XLM-R / SigLIP 2 | 310 / 437 ms | 17.2 / 24.3 h |

In Sightline the 1024 px image comes from Nextcloud's previews rather than from decoding
the original, so the decode part of these numbers is replaced by preview cost, which was not
measured on this machine.

## Consequences for Sightline

- XLM-R B/32 becomes the default: on this library it is clearly better for Polish queries,
  close for English, and 3.75× cheaper to encode than SigLIP 2 B/16 (about 3 h of encoding
  for 200k photos on all cores instead of about 11 h). SigLIP 2 B/16 stays an option for
  English-only use.
- RAW files can be indexed from their embedded JPEG, as long as the orientation is taken
  from the RAW container. Nextcloud core has no RAW previews, and 45 % of this library is
  RAW, so Sightline needs a RAW path that extracts the embedded JPEG (with one long-lived
  extractor, not a process per file).
- OpenVINO on the CPU is not worth a second runtime. The iGPU remains unmeasured.
- The search process needs about 2 GB of memory for the text tower; it should be loaded
  once, not per worker.
