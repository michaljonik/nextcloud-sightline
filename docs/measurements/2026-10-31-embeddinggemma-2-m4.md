# EmbeddingGemma 2 on a MacBook Air M4

Date: 2026-10-31. Machine: MacBook Air M4 (4 performance + 6 efficiency cores, 24 GB; dev,
not the N305 target). Adds one model to [the M4 model comparison](2026-10-24-model-comparison-m4.md)
and [the jina-clip-v2 note](2026-10-25-jina-clip-v2-m4.md): same 678 stock photos, same 27
judged English queries and their Polish translations, same scoring.

Question: Google's EmbeddingGemma 2 (October 2026) puts text and images in one vector
space, is Apache-2.0 and ships an ONNX export. Does it find better photos than LAION XLM-R
B/32 (the default), and what does it cost?

## Model

| | |
|---|---|
| Export | `onnx-community/embeddinggemma-2-ONNX`, fp32, revision `daa72c51`; processor and tokenizer from `google/embeddinggemma-2`, revision `f9972ac2` |
| Licence | Apache-2.0 |
| Image path | vision encoder (170M parameters), its soft tokens then go through the text transformer |
| Text path | Gemma transformer (130M) + token embeddings (140M), mean pooling, 512 → 768 projection |
| Embedding | 768 dims (Matryoshka to 512/256/128; truncation was not tested) |
| Vision token budget | 70, 140, 280 (default), 560 or 1120 soft tokens per image |

The model does not fit the Immich layout that Sightline's encoder reads (`visual/` and
`textual/`, one ONNX graph each). An image is resized with its aspect ratio kept so that
it fits the token budget (at 280 tokens a 3:2 photo becomes 2,520 patches of 16 px, against
49 for a B/32 model at 224 px), encoded by the vision graph, and then run through the text
graph as `<bos><boi>` + soft tokens + `<eoi><eos>`. [`gemma2_eval.py`](../../spikes/model-eval/gemma2_eval.py)
does this with ONNX Runtime and the model's own processor. Against the
sentence-transformers reference (bf16 weights) its vectors have cosine ≥ 0.9986 for images
and ≥ 0.9999 for text.

The model card prefixes retrieval queries with `task: search result | query: `. Both
variants were scored.

## Method

- As in the comparison: top 8 per query, nP@8 = relevant hits in the top 8 / min(8, number
  of relevant photos), MRR = 1 / rank of the first relevant photo.
- The 70-token top 8 (both query variants, both languages) contained 71 photo-query pairs
  that no earlier method had returned. They were judged by eye the same way, with the
  photos already judged relevant for that query shown alongside. 8 were relevant (foggy
  morning, sad, parenthood, crazy child, snowy mountains ×2, shallow depth-of-field,
  texture). The earlier models' scores do not change.
- Timings: ONNX Runtime 1.31 CPU, batch 1, the first 30 photos of the set at 1024 px,
  one thread, XLM-R B/32 re-timed in the same session (19.8–21.2 ms per photo, faster than
  the 34 ms in the earlier notes; the ratios below use the same-session number). Times
  include the processor's resize and patching.
- Background load was 0.5–1.5 cores (app UI, security agent, system indexing) during the
  single-thread timings, on a 10-core machine. The 70-token timing was run three times at
  different times of the session (497, 519, 528 ms); the 280-token and int8 rows are one
  run each. Multi-thread runs overlapped with heavier background load (the Nextcloud
  desktop client's file provider and an app UI process, about 3.5 cores together) and are
  not reported.
- One judge, stock photos, 27 queries: differences of a few points are indicative only.

## Cost

| Model | Image encode, 1 thread | Relative to XLM-R | Peak RSS, image side | Query, 1 thread | Peak RSS, query side |
|---|---|---|---|---|---|
| XLM-R B/32 | 20 ms | 1× | 0.5 GB | 25 ms | 2.5 GB |
| **EmbeddingGemma 2, 70 tokens** | **497–528 ms** | **25–27×** | 2.1 GB | 14 ms | 1.3 GB |
| EmbeddingGemma 2, 280 tokens | 4,804 ms | 229× | 2.4 GB | | |
| EmbeddingGemma 2, 70 tokens, int8 export | 752 ms | 37× | 1.3 GB | | |

- The vision encoder is 90–97 % of the image time. The text transformer adds 28 ms at 70
  tokens and 118 ms at 280.
- The int8 export (`*_quantized.onnx`) is slower than fp32 on the M4. x86 CPUs with VNNI
  (the N305 has AVX-VNNI) may behave differently; not measured.
- The query side is cheaper than XLM-R's: about half the time and half the memory. The
  RSS figure includes importing transformers and torch for the tokenizer.
- Extrapolated to the N305 with the ratio to XLM-R B/32 measured there (204 ms on one
  thread): about 5 s per photo on one thread at 70 tokens, about 12 days of one core for
  200k photos (XLM-R: 11 h). Not measured on the N305, and how the encode scales with
  threads was not measured cleanly on the M4.

## Quality

| Model | nP@8 EN | nP@8 PL | MRR EN | MRR PL |
|---|---|---|---|---|
| XLM-R B/32 | **0.881** | **0.852** | 0.899 | **0.899** |
| SigLIP 2 B/16-224 | 0.836 | 0.819 | 0.946 | **0.899** |
| Jina CLIP v2, no query prefix | 0.869 | 0.790 | 0.902 | 0.842 |
| **EmbeddingGemma 2, 70 tokens, no query prefix** | 0.880 | 0.830 | **0.963** | 0.891 |
| EmbeddingGemma 2, 70 tokens, with query prefix | 0.875 | 0.807 | 0.920 | 0.793 |

Queries better / worse than XLM-R B/32 (nP@8), 70 tokens without prefix: 5 / 6 in English,
4 / 6 in Polish.

- English is on par with XLM-R, with the best first-hit rank of all models measured
  (MRR 0.963). Polish is 2 points below XLM-R and 1 point above SigLIP 2 B/16.
- The largest losses are "food on a plate" / "jedzenie na talerzu" (0.75 against 1.00 in
  both languages; coffee beans and wooden boards ranked in), "group photo of people
  smiling" (0.67 against 1.00; two scanned ID-card pages ranked in) and "akcesoria
  biurowe" (0.62 against 0.88). The largest gains are "boy with a blue backpack" (1.00
  against 0.50), "blue eyed happy child" in both languages and "city street at night" in
  both languages.
- The documented query prefix hurts, as with jina-clip-v2: −0.5 points in English, −2.3 in
  Polish and −10 points MRR in Polish.
- The default 280-token budget was not scored: a bug in the sharding code of the indexing
  run overwrote two of its three vector files. At 229× the
  image cost of XLM-R its quality would not change the conclusion.

## Consequences for Sightline

- Not a candidate for the default. At its cheapest setting it matches XLM-R B/32 in
  English and is slightly worse in Polish, at 25–27× the image cost. The image side is what
  sets first-indexing time on the N305, so that is roughly 12 days of encoding instead of
  11 hours for 200k photos.
- The licence is not the obstacle: Apache-2.0 fits an open-source default, unlike
  jina-clip-v2 and the other strong Polish scorers.
- Supporting it as an optional model would need a second encoder adapter (vision graph +
  text graph with soft tokens, the model's own resize and patching) next to the Immich
  layout. Not worth it at this cost.
- Worth re-checking if a version with a vision tower about ten times cheaper appears: the
  query side (14 ms, 1.3 GB) is already cheaper than XLM-R's.
- No change to the default (XLM-R B/32) or to the English-only option (SigLIP 2 B/16).
