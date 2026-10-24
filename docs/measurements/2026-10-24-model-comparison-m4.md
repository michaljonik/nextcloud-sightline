# Model comparison: SigLIP 2 variants and Moondream2 captions

Date: 2026-10-24. Machine: MacBook Air M4 (dev, not the N305 target). Follows
[the first model spike](2026-10-24-model-spike-m4.md); same 678 stock photos, same 29
English queries.

Question: is there a model about as fast as SigLIP 2 B/16-224 that finds noticeably better
results? And how does a caption-based approach (a small vision-language model describes
each photo, queries are matched against the descriptions) compare?

## Candidates

A literature check (October 2026) found no 2025–2026 dual encoder that clearly beats SigLIP 2
B/16 at the same image cost with independent numbers; all published gains are
vendor-reported. TULIP B/16 claims +2 R@1 at the same cost but has no licence on its weights
and no ONNX export. PE-Core B/16 reports lower text-to-image recall. Tested here:

| Method | Export | Licence | Embedding dims |
|---|---|---|---|
| SigLIP 2 B/16, 224 px (current default) | `immich-app/ViT-B-16-SigLIP2__webli` | Apache-2.0 | 768 |
| SigLIP 2 B/16, 256 px | `onnx-community/siglip2-base-patch16-256-ONNX` | Apache-2.0 | 768 |
| SigLIP 2 B/16, 384 px | `onnx-community/siglip2-base-patch16-384-ONNX` | Apache-2.0 | 768 |
| SigLIP 2 L/16, 256 px | `immich-app/ViT-L-16-SigLIP2-256__webli` | Apache-2.0 | 1024 |
| SigLIP 2 B/32, 256 px | `immich-app/ViT-B-32-SigLIP2-256__webli` | Apache-2.0 | 768 |
| Moondream2 (2025-06-21) short captions + `multilingual-e5-small` | `vikhyatk/moondream2` | Apache-2.0 / MIT | 384 |

## Method

- Each method returns its top 8 for each query. The union over all methods (504 photos) was
  judged by eye: relevant or not. Two queries have no relevant photo in the set
  ("birthday party with a cake", "screenshot of a phone"), leaving 27.
- **nP@8**: relevant hits in the top 8 divided by min(8, number of relevant photos), so a
  query with 2 relevant photos can still score 1.0.
- **MRR**: 1 / rank of the first relevant photo.
- One judge, stock photos, 27 queries: treat differences of a few points as indicative,
  not significant.

## Results

| Method | Image encode, 1 thread | Relative cost | nP@8 | MRR | Queries better / worse than B/16-224 |
|---|---|---|---|---|---|
| SigLIP 2 B/32-256 | 44 ms | 0.37× | 0.870 | 0.909 | 3 / 2 |
| **SigLIP 2 B/16-224** | 121 ms | 1× | 0.847 | 0.945 | — |
| SigLIP 2 B/16-256 | 143 ms | 1.18× | 0.866 | 0.927 | 2 / 1 |
| SigLIP 2 B/16-384 | 358 ms | 2.95× | 0.901 | 0.909 | 5 / 0 |
| SigLIP 2 L/16-256 | 527 ms | 4.35× | 0.896 | 0.944 | 6 / 3 |
| Moondream2 captions + e5 | ~8 s (GPU, MPS) | ~65× | 0.745 | 0.799 | 1 / 9 |

- Timings: ONNX Runtime CPU, batch 1, `intra_op_num_threads=1`, 60 photos, idle machine.
  They are slower than the multi-threaded 53 ms in the first spike; only the ratios carry
  over. Moondream ran on the M4 GPU (it crashes on CPU-only with mixed devices) and is not
  directly comparable, but the order of magnitude is.
- Peak RSS with only the image tower loaded: ~0.55 GB for the B models, 1.9 GB for L/16.
- Query encoding (text tower) is 15–25 ms for all SigLIP variants except L/16 (~115 ms).
  At 200k photos a brute-force scan adds ~40 ms on the M4 (measured on synthetic vectors).

## Findings

- **224 → 256 px buys nothing measurable** (+2 points, within noise). This matches Immich's
  measurement of the same step for SigLIP 1.
- **B/16-384 and L/16-256 are ~5 points better**, mostly by filling more of the top 8 with
  relevant photos, not by ranking the first hit higher (MRR is equal or lower). B/16-384
  was never worse than the baseline on any query. Both cost 3–4× more per photo.
- **B/32-256 is as good as the baseline at ~0.4× the cost.** It is the obvious choice if
  first indexing on the N305 turns out too slow.
- **Moondream2 captions are worse and far slower.** Short captions leave out what queries
  ask for ("trophy", "security device", "dog working on a laptop" found 0–1 of the right
  photos). Normal-length captions are more detailed but take ~6 s per photo for generation
  alone. At ~8 s per photo, 200k photos would take ~18 days on the M4 GPU.
- Polish was not tested here. Immich's published table has L/16-256 clearly ahead in Polish
  (81.3 vs 76.5 for B/16-224); resolution barely changes Polish recall.

## Consequences for Sightline

- Keep **B/16-224 as the default**. Offer **B/32-256** as the fast option and **B/16-384**
  (English) or **L/16-256** (Polish) as the quality option. All use the same pipeline,
  so this is a setting, not code.
- Do not build a caption-based index as the main search path.
- The N305 run decides which option is the default for a 200k-photo library.
