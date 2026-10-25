# Model spike: SigLIP 2 B/16 on a MacBook Air M4

Date: 2026-10-24. Script: [`spikes/model-eval/spike.py`](../../spikes/model-eval/spike.py).

A rough first check, not a benchmark. Two questions: does the default model find the right
photos for plain English queries, and what does each step cost per photo. The machine is the
dev laptop, not the N305 target, so the timings say nothing definite about the target.

## Setup

- Model: `ViT-B-16-SigLIP2__webli`, Immich ONNX export (fp32, 768 dims, 224 px input,
  squash resize). Visual tower 372 MB, text tower 1129 MB on disk.
- Runtime: ONNX Runtime 1.31, CPU execution provider, batch size 1, Python 3.12.
- Photos: 678 JPEGs, a former stock-photo portfolio shot on Nikon DSLRs. Median 12 MP,
  max 36 MP. Subjects: people and children, landscapes, winter sports, objects and
  textures, a few scanned documents. No RAW files in this set.
- Pipeline per photo: decode the original, downscale to a 1024 px long edge (what Sightline
  will request from `IPreview`), resize to 224 px, encode, L2-normalise. Search is a dot
  product over all vectors.
- Queries: 29 English queries, some written for this photo set, some generic.

## Timings (M4, 10 cores)

| Step | Median | p90 |
|---|---|---|
| Decode full-size JPEG (Pillow) | 57 ms | 122 ms |
| Downscale to 1024 px | 22 ms | 34 ms |
| Image encode, ORT default threads | 53 ms | 60 ms |
| Image encode, 4 threads | 56 ms | 60 ms |
| Image encode, 1 thread | 71 ms | 74 ms |
| Text encode (query) | 15 ms | — |

- Encoding all 678 photos from the 1024 px copies took about 40 s in one sequential process
  (~17 photos/s).
- Extra threads barely help a batch-1 encode (71 → 53 ms from 1 to 10 threads). On a
  multi-core box, parallel workers with one thread each will scale better than one worker
  with many threads.
- The CoreML execution provider gave no speedup (51 ms) and took 5.5 s to load.
- Decoding and downscaling the original cost about as much as encoding (~80 ms vs ~55 ms).
  Nextcloud's own preview generation does more work than this (a 4096 px max preview first),
  so on the target, preview cost may matter as much as the model.
- Memory: peak RSS 0.6 GB with only the visual tower loaded, 2.4 GB once the text tower is
  loaded as well.

## Quality (English queries)

Judged by looking at the top 8 results per query.

- Clearly right at the top: concrete objects and scenes. Examples: "man running with a
  dog", "foggy morning", "autumn leaves", "trophy", "security device" (USB tokens, routers,
  a wall keypad), "skiing", "jet engine", "a man on a ski-lift", "dog working on a laptop",
  "texture", "snowy mountains", "close-up of a flower", "document or receipt".
- Reasonable: softer concepts, e.g. "sad", "parenthood", "crazy child",
  "father teaching kids", "blue eyed happy child".
- Weak: photographic technique. "Shallow depth-of-field" returned misty lake
  landscapes, not photos with a blurred background.
- Nothing to find: queries for things not in the set ("birthday party with a cake",
  "screenshot of a phone") returned the nearest unrelated photos. Their scores were lower,
  but not consistently.

## Score calibration

Search always returns *something*, so Unified Search needs a cut-off or it will show 25
unrelated photos for every query. Raw cosine scores per query (best / 3rd / 10th):

| Query | Best | 3rd | 10th | In the set? |
|---|---|---|---|---|
| blue eyed happy child | 0.173 | 0.151 | 0.098 | yes |
| dog working on a laptop | 0.162 | 0.159 | 0.031 | yes, 3 photos |
| trophy | 0.142 | 0.064 | 0.055 | yes, 1 photo |
| food on a plate | 0.107 | 0.088 | 0.048 | yes |
| group photo of people smiling | 0.075 | 0.059 | 0.044 | yes |
| a red sports car | 0.097 | 0.079 | 0.041 | no |
| underwater coral reef | 0.088 | 0.081 | 0.069 | no |
| an elephant | 0.053 | 0.050 | 0.041 | no |
| people at a birthday party with a cake | 0.037 | 0.030 | 0.006 | no |

- Real matches mostly score above ~0.10, and a sharp drop after the true hits is common
  (trophy: 0.142 → 0.064).
- A fixed threshold does not separate the two cleanly: "group photo of people smiling"
  (0.075, a real match) scores below "a red sports car" (0.097, no match).
- A relative cut-off (drop from the best score) combined with a low absolute floor looks
  more promising. So does SigLIP's own sigmoid with its learned scale and bias, which the
  Immich export does not include. Both need testing on a second photo set.

## Caveats

- Stock photos are well composed, single-subject and well lit. A private library (blurry
  shots, screenshots, near-duplicates) will be harder.
- English queries only. Polish comes next.
- No RAW files in this set, so the embedded-JPEG path for NEF/DNG was not exercised.
- M4 timings only. The N305 needs its own run.

## Next

1. The same run on the N305: ORT on CPU, then OpenVINO.
2. Polish queries on the same set: translate the 29 queries and compare the top-8 overlap.
3. A score cut-off: try a relative drop plus a floor, and try the sigmoid with the model's
   learned bias.
4. A second set with RAW (NEF/DNG) and typical phone photos.
