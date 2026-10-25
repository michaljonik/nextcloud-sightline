# Model comparison: SigLIP 2 variants and Moondream2 captions

Date: 2026-10-24. Machine: MacBook Air M4 (dev, not the N305 target). Follows
[the first model spike](2026-10-24-model-spike-m4.md); same 678 stock photos, the same 29
English queries plus their Polish translations
([`queries-pl.txt`](../../spikes/model-eval/queries-pl.txt), same order).

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
| LAION XLM-RoBERTa-base ViT-B/32 (multilingual) | `immich-app/XLM-Roberta-Base-ViT-B-32__laion5b_s13b_b90k` | MIT | 512 |
| Moondream2 (2025-06-21) short captions + `multilingual-e5-small` | `vikhyatk/moondream2` | Apache-2.0 / MIT | 384 |

## Method

- Each method returns its top 8 for each query, in English and in Polish. The union over all
  methods and both languages (832 photo-query pairs) was judged by eye: relevant or not.
  A Polish query shares the judgements of its English original. Two queries have no relevant photo in the set
  ("birthday party with a cake", "screenshot of a phone"), leaving 27.
- **nP@8**: relevant hits in the top 8 divided by min(8, number of relevant photos), so a
  query with 2 relevant photos can still score 1.0.
- **MRR**: 1 / rank of the first relevant photo.
- One judge, stock photos, 27 queries: treat differences of a few points as indicative,
  not significant.

## Results

| Method | Image encode, 1 thread | Relative cost | nP@8 EN | nP@8 PL | MRR EN | MRR PL |
|---|---|---|---|---|---|---|
| **XLM-R B/32** | 34 ms | 0.31× | 0.882 | **0.854** | 0.899 | 0.899 |
| SigLIP 2 B/32-256 | 44 ms | 0.37× | 0.862 | 0.829 | 0.909 | 0.853 |
| **SigLIP 2 B/16-224** | 110–121 ms | 1× | 0.838 | 0.820 | 0.946 | 0.899 |
| SigLIP 2 B/16-256 | 143 ms | 1.18× | 0.857 | 0.817 | 0.927 | 0.917 |
| SigLIP 2 B/16-384 | 358 ms | 2.95× | **0.892** | **0.854** | 0.909 | 0.894 |
| SigLIP 2 L/16-256 | 527 ms | 4.35× | 0.887 | 0.764 | 0.944 | 0.829 |
| Moondream2 captions + e5 | ~8 s (GPU, MPS) | ~65× | 0.742 | 0.385 | 0.799 | 0.432 |

Queries better / worse than B/16-224 (nP@8), EN and PL:

| Method | EN | PL |
|---|---|---|
| XLM-R B/32 | 4 / 2 | 7 / 4 |
| SigLIP 2 B/32-256 | 3 / 2 | 6 / 3 |
| SigLIP 2 B/16-256 | 2 / 1 | 3 / 3 |
| SigLIP 2 B/16-384 | 5 / 0 | 5 / 2 |
| SigLIP 2 L/16-256 | 6 / 3 | 5 / 8 |
| Moondream2 captions + e5 | 1 / 9 | 1 / 19 |

- Timings: ONNX Runtime CPU, batch 1, `intra_op_num_threads=1`, 60 photos, idle machine
  (B/16-224 measured twice: 121 and 110 ms). Slower than the multi-threaded 53 ms in the
  first spike; only the ratios carry over. Moondream ran on the M4 GPU (it crashes on
  CPU-only with mixed devices) and is not directly comparable, but the order of magnitude is.
- Peak RSS with only the image tower loaded: ~0.5 GB for the B models, 1.9 GB for L/16.
- Query encoding (text tower), 1 thread: 18 ms for SigLIP 2 B/16, 28 ms for XLM-R, ~115 ms
  for L/16. At 200k photos a brute-force scan adds ~40 ms on the M4 (synthetic vectors).

## Findings

- Polish works. SigLIP 2 B/16-224 loses about 2 points going from English to Polish
  queries (0.838 → 0.820). Every multilingual dual encoder here stays within ~4 points.
- XLM-R B/32 is the best value in this test: best or equal-best nP@8 in both languages
  at about a third of B/16's image cost, and 512-dim vectors (two thirds of the index size).
  This is better than Immich's published table suggests (there XLM-R B/32 and SigLIP 2 B/16
  are about equal in Polish), so it needs confirming on a second photo set.
- 224 → 256 px buys nothing measurable, in either language. This matches Immich's
  measurement of the same step for SigLIP 1.
- B/16-384 is ~5 points better in English and ~3 in Polish at ~3× the cost, mostly by
  filling more of the top 8 with relevant photos, not by ranking the first hit higher.
- L/16-256 did not show its published Polish advantage here (0.764 vs 0.820). It is
  not a tokenizer bug: tokens are identical to B/16's. It loses on a few queries with only
  1–2 relevant photos ("security device", "boy with a blue backpack"), where one swap moves
  the score a lot. With 27 queries this is noise, not proof that L/16 is worse, but it also
  gives no reason to pay 4× for it.
- Moondream2 captions are worse in English and collapse in Polish (0.385). The captions
  are English and short; the multilingual text embedding does not bridge "puchar" to a
  caption that never mentions a trophy. Short captions also miss what queries ask for, and
  at ~8 s per photo 200k photos would take ~18 days on the M4 GPU.

## Consequences for Sightline

- Candidates for the default: XLM-R B/32 and SigLIP 2 B/16-224. XLM-R B/32 is cheaper
  and at least as good here, in both languages. Decide after the N305 run and a second,
  less curated photo set (phone photos, screenshots, near-duplicates).
- Quality option: SigLIP 2 B/16-384 (~3× cost). Drop L/16-256 and B/16-256 from the
  shortlist: no measured gain for their cost.
- All options use the same pipeline (Immich-style ONNX export), so the model stays a
  setting, not code.
- Do not build a caption-based index, and do not plan on query translation for Polish:
  the multilingual encoders already handle Polish queries directly.
