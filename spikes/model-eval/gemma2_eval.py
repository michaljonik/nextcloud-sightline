# SPDX-FileCopyrightText: 2026 Michal Jonik
# SPDX-License-Identifier: AGPL-3.0-or-later

"""Index and score google/embeddinggemma-2 on the same photos and queries as spike.py.

EmbeddingGemma 2 does not fit the Immich layout: an image goes through a vision encoder,
and its soft tokens then go through the same text transformer as queries. This runs the
onnx-community export with ONNX Runtime and uses the model's own processor (transformers)
for resizing, patching and tokenising. `check` compares the ONNX path against the
sentence-transformers reference on a few photos and queries.

    uv run gemma2_eval.py check
    uv run gemma2_eval.py index --tokens 280 [--dtype q8] [--threads 1] [--limit 60 --timing-only]
    uv run gemma2_eval.py index --tokens 280 --threads 1 --shard 0/3   # and 1/3, 2/3, then: merge --tokens 280
    uv run gemma2_eval.py score --tokens 280 [--dtype q8] [--prefix]

Vectors are written to out/embeddinggemma-2-t{tokens}[-q8]/ with the row order (and
thumbnails) of the XLM-R B/32 index, so metrics and contact sheets line up.
"""

from __future__ import annotations

import argparse
import json
import time
from pathlib import Path

import numpy as np
import onnxruntime as ort
from transformers import AutoProcessor

import metrics
import spike

ONNX = spike.MODELS / "embeddinggemma-2-onnx" / "onnx"
HF = spike.MODELS / "embeddinggemma-2-hf"  # processor, tokenizer and reference weights
BASE = "XLM-Roberta-Base-ViT-B-32__laion5b_s13b_b90k"  # row order and thumbnails
# Model card: "task: search result | query: " for retrieval queries; images take no prefix.
QUERY_PREFIX = "task: search result | query: "


def name(tokens: int, dtype: str) -> str:
    return f"embeddinggemma-2-t{tokens}" + ("" if dtype == "fp32" else f"-{dtype}")


class Gemma:
    def __init__(self, dtype: str, threads: int, vision: bool = True):
        suffix = {"fp32": "", "q8": "_quantized"}[dtype]
        opts = ort.SessionOptions()
        opts.intra_op_num_threads = threads
        load = lambda part: ort.InferenceSession(str(ONNX / f"{part}{suffix}.onnx"), opts,  # noqa: E731
                                                 providers=["CPUExecutionProvider"])
        self.processor = AutoProcessor.from_pretrained(HF)
        self.model = load("model")
        self.vision = load("vision_encoder") if vision else None
        self.empty = np.zeros((0, 512), dtype=np.float32)

    def _embed(self, enc: dict, image_features: np.ndarray | None = None) -> np.ndarray:
        feed = {"input_ids": enc["input_ids"], "attention_mask": enc["attention_mask"],
                "image_features": self.empty if image_features is None else image_features,
                "video_features": self.empty, "audio_features": self.empty}
        return spike.normalize(self.model.run(["sentence_embedding"], feed)[0][0])

    def encode_image(self, img, tokens: int) -> tuple[np.ndarray, float, float]:
        """Return the vector and the vision-encoder and transformer times in ms."""
        enc = self.processor(text=["<|image|>"], images=[[img]], return_tensors="np", max_soft_tokens=tokens)
        t0 = time.perf_counter()
        features = self.vision.run(["image_features"], {"pixel_values": enc["pixel_values"],
                                                        "pixel_position_ids": enc["image_position_ids"]})[0]
        t1 = time.perf_counter()
        vec = self._embed(enc, features)
        t2 = time.perf_counter()
        return vec, (t1 - t0) * 1e3, (t2 - t1) * 1e3

    def encode_text(self, text: str) -> np.ndarray:
        return self._embed(self.processor(text=[" ".join(text.split())], return_tensors="np"))


def base_rows() -> list[dict]:
    return json.loads((spike.OUT_ROOT / BASE / "index.json").read_text())["rows"]


def cmd_check(args: argparse.Namespace) -> None:
    from sentence_transformers import SentenceTransformer

    rows = base_rows()[:: len(base_rows()) // args.n][: args.n]
    imgs = [spike.to_preview(spike.load_image(Path(r["path"]))) for r in rows]
    texts = [QUERY_PREFIX + "dog in the forest", "mglisty poranek", QUERY_PREFIX + "urodzinowy tort"]
    g = Gemma("fp32", 0)
    onnx_img = np.stack([g.encode_image(im, 280)[0] for im in imgs])
    onnx_txt = np.stack([g.encode_text(t) for t in texts])
    ref = SentenceTransformer(str(HF), device="cpu")
    ref_img = ref.encode(imgs, normalize_embeddings=True)
    ref_txt = ref.encode(texts, normalize_embeddings=True)
    for label, a, b in (("image", onnx_img, ref_img), ("text", onnx_txt, ref_txt)):
        cos = (a * b).sum(1)
        print(f"{label}: cosine ONNX vs reference min {cos.min():.5f}, max |diff| {np.abs(a - b).max():.5f}")
    print("image-text similarity matrix, ONNX:\n", np.round(onnx_img @ onnx_txt.T, 3))
    print("reference:\n", np.round(ref_img @ ref_txt.T, 3))


def cmd_index(args: argparse.Namespace) -> None:
    rows = base_rows()[: args.limit] if args.limit else base_rows()
    out = spike.OUT_ROOT / name(args.tokens, args.dtype)
    if args.shard:  # "i/n": run n single-thread processes side by side, then `merge`
        shard, n = map(int, args.shard.split("/"))
        rows = rows[shard::n]
    g = Gemma(args.dtype, args.threads)
    print(f"loaded, peak RSS {spike.rss_mb():.0f} MB; {len(rows)} photos, {args.tokens} tokens, "
          f"{args.dtype}, {args.threads or 'default'} threads")
    vectors, timings = [], []
    t_start = time.perf_counter()
    for i, row in enumerate(rows):
        img = spike.to_preview(spike.load_image(Path(row["path"])))
        t0 = time.perf_counter()
        vec, vision_ms, text_ms = g.encode_image(img, args.tokens)
        timings.append(((time.perf_counter() - t0) * 1e3, vision_ms, text_ms))
        vectors.append(vec)
        if (i + 1) % 100 == 0:
            print(f"  {i + 1}/{len(rows)}")
    wall = time.perf_counter() - t_start
    t = np.array(timings)
    print(f"encode (incl. processor) median {np.median(t[:, 0]):.0f} ms  p90 {np.percentile(t[:, 0], 90):.0f} ms; "
          f"vision {np.median(t[:, 1]):.0f} ms, transformer {np.median(t[:, 2]):.0f} ms; "
          f"wall {wall:.0f} s; peak RSS {spike.rss_mb():.0f} MB")
    if args.timing_only:
        return
    out.mkdir(parents=True, exist_ok=True)
    if args.shard:
        np.save(out / f"shard-{shard}-of-{n}.npy", np.stack(vectors).astype(np.float32))
        return
    write_index(out, rows, np.stack(vectors))


def cmd_merge(args: argparse.Namespace) -> None:
    out = spike.OUT_ROOT / name(args.tokens, args.dtype)
    parts = sorted(out.glob("shard-*-of-*.npy"))
    n = len(parts)
    rows = base_rows()
    vectors = np.zeros((len(rows), 768), dtype=np.float32)
    for part in parts:
        i = int(part.stem.split("-")[1])
        vectors[i::n] = np.load(part)
    write_index(out, rows, vectors)


def write_index(out: Path, rows: list[dict], vectors: np.ndarray) -> None:
    np.save(out / "vectors.npy", vectors.astype(np.float32))
    (out / "index.json").write_text(json.dumps({"rows": rows, "failures": []}, indent=1))
    if not (out / "thumbs").exists():
        (out / "thumbs").symlink_to(f"../{BASE}/thumbs")
    print(f"wrote {out}")


def cmd_score(args: argparse.Namespace) -> None:
    out = spike.OUT_ROOT / name(args.tokens, args.dtype)
    rows, vectors = json.loads((out / "index.json").read_text())["rows"], np.load(out / "vectors.npy")
    if args.dims:  # Matryoshka: truncate and re-normalise
        vectors = spike.normalize(vectors[:, : args.dims])
    root = Path(rows[0]["path"]).parent
    rel = [str(Path(r["path"]).relative_to(root)) for r in rows]
    judg = json.loads((spike.OUT_ROOT / "judgements.json").read_text())
    pool = json.loads((spike.OUT_ROOT / "judged-pool.json").read_text())
    g = Gemma(args.dtype, 0, vision=False)
    prefix = QUERY_PREFIX if args.prefix else ""
    en = [q for q in spike_queries("queries.txt")]
    pl = spike_queries("queries-pl.txt")
    unjudged = {}
    for lang, queries in (("EN", en), ("PL", pl)):
        scores, lat = [], []
        for q_en, q in zip(en, queries):
            if not judg.get(q_en):
                continue
            t0 = time.perf_counter()
            qv = g.encode_text(prefix + q)
            lat.append((time.perf_counter() - t0) * 1e3)
            qv = spike.normalize(qv[: args.dims]) if args.dims else qv
            order = np.argsort(-(vectors @ qv))
            scores.append(metrics.score(order, rel, set(judg[q_en])))
            new = [rel[j] for j in order[:8] if rel[j] not in pool.get(q_en, [])]
            if new:
                unjudged.setdefault(q_en, set()).update(new)
        s = np.array(scores)
        print(f"{lang}: {len(s)} queries  nP@8 {s[:, 0].mean():.3f}  MRR {s[:, 1].mean():.3f}  "
              f"query median {np.median(lat):.0f} ms")
    n = sum(len(v) for v in unjudged.values())
    print(f"unjudged photo-query pairs in top 8: {n} across {len(unjudged)} queries")
    if args.write_unjudged:
        Path(args.write_unjudged).write_text(json.dumps({q: sorted(v) for q, v in unjudged.items()}, indent=1))


def spike_queries(file: str) -> list[str]:
    return [q.strip() for q in (spike.HERE / file).read_text().splitlines() if q.strip() and not q.startswith("#")]


def main() -> None:
    p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = p.add_subparsers(dest="cmd", required=True)
    pc = sub.add_parser("check")
    pc.add_argument("-n", type=int, default=6)
    pc.set_defaults(func=cmd_check)
    for cmd, func in (("index", cmd_index), ("merge", cmd_merge), ("score", cmd_score)):
        sp = sub.add_parser(cmd)
        sp.add_argument("--tokens", type=int, default=280, choices=[70, 140, 280, 560, 1120])
        sp.add_argument("--dtype", choices=["fp32", "q8"], default="fp32")
        sp.set_defaults(func=func)
        if cmd == "index":
            sp.add_argument("--threads", type=int, default=0)
            sp.add_argument("--limit", type=int, default=0)
            sp.add_argument("--timing-only", action="store_true")
            sp.add_argument("--shard", help="i/n: encode every n-th photo from i, save a shard")
        elif cmd == "score":
            sp.add_argument("--prefix", action="store_true", help="prepend the model card's query prefix")
            sp.add_argument("--dims", type=int, default=0, help="truncate to this many dims (Matryoshka)")
            sp.add_argument("--write-unjudged")
    args = p.parse_args()
    args.func(args)


if __name__ == "__main__":
    main()
