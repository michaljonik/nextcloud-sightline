# SPDX-FileCopyrightText: 2026 Michal Jonik
# SPDX-License-Identifier: AGPL-3.0-or-later

"""Compare search methods side by side on the same photos and queries.

Methods are image-text models indexed with `spike.py --model NAME index ...`, plus optionally
Moondream2 captions (from caption.py) searched with a multilingual text embedding model.

    uv run compare.py --models ViT-B-16-SigLIP2__webli OTHER --captions out/captions-short.json \
        --file queries.txt

Writes out/compare.html, contact sheets in out/compare-sheets/, and out/pool.json: for every
query, the union of all methods' top-k. Mark relevant photos in out/judgements.json
({query: [relative paths]}) and rerun to get precision@k per method.
"""

from __future__ import annotations

import argparse
import html
import json
import os
import time
from pathlib import Path

import numpy as np
from PIL import Image, ImageDraw, ImageOps

import spike

TEXT_MODEL = "intfloat/multilingual-e5-small"  # MIT; e5 expects "query: " / "passage: " prefixes
OUT = spike.OUT_ROOT


class ModelMethod:
    def __init__(self, name: str):
        spike.MODEL = name
        self.name = name
        self.out = spike.out_dir()
        index = json.loads((self.out / "index.json").read_text())
        self.rows = index["rows"]
        self.vectors = np.load(self.out / "vectors.npy")
        self.text = None  # loaded lazily; spike.MODEL must point at this model when it loads

    def search(self, q: str) -> np.ndarray:
        if self.text is None:
            spike.MODEL = self.name
            self.text = spike.TextEncoder("cpu")
        return self.vectors @ self.text.encode(q)

    def release(self) -> None:
        self.text = None


class CaptionMethod:
    def __init__(self, captions_file: Path, rows: list[dict]):
        from sentence_transformers import SentenceTransformer  # heavy import, only if used

        self.name = "Moondream2 captions + e5"
        root = Path(os.path.commonpath([r["path"] for r in rows]))
        by_rel = json.loads(captions_file.read_text())
        self.captions = [by_rel.get(str(Path(r["path"]).relative_to(root)), {}).get("caption", "") for r in rows]
        self.have = np.array([bool(c) for c in self.captions])
        self.e5 = SentenceTransformer(TEXT_MODEL, device="cpu")
        self.vectors = self.e5.encode([f"passage: {c}" for c in self.captions], normalize_embeddings=True, batch_size=64)
        print(f"captions: {self.have.sum()}/{len(rows)} photos")

    def release(self) -> None:
        pass

    def search(self, q: str) -> np.ndarray:
        scores = self.vectors @ self.e5.encode(f"query: {q}", normalize_embeddings=True)
        scores[~self.have] = -1
        return scores


def main() -> None:
    p = argparse.ArgumentParser()
    p.add_argument("queries", nargs="*")
    p.add_argument("--file")
    p.add_argument("--models", nargs="+", default=[spike.MODEL])
    p.add_argument("--captions")
    p.add_argument("-k", type=int, default=8)
    args = p.parse_args()

    queries = list(args.queries)
    if args.file:
        queries += [q.strip() for q in Path(args.file).read_text().splitlines()
                    if q.strip() and not q.startswith("#")]

    methods = [ModelMethod(name) for name in args.models]
    # All models index the same photo folder; align rows by path so results are comparable.
    base = methods[0]
    root = Path(os.path.commonpath([r["path"] for r in base.rows]))
    rel = [str(Path(r["path"]).relative_to(root)) for r in base.rows]
    for m in methods[1:]:
        order = {str(Path(r["path"]).relative_to(root)): i for i, r in enumerate(m.rows)}
        m.vectors = m.vectors[[order[r] for r in rel]]
    if args.captions:
        methods.append(CaptionMethod(Path(args.captions), base.rows))
    captions = getattr(methods[-1], "captions", [""] * len(rel))

    judgements_file = OUT / "judgements.json"
    judgements = json.loads(judgements_file.read_text()) if judgements_file.exists() else {}
    # Method by method, then free its text model: keeps only one text tower in RAM.
    top_hits, latency = {}, {m.name: [] for m in methods}
    for m in methods:
        for q in queries:
            t0 = time.perf_counter()
            scores = m.search(q)
            latency[m.name].append(time.perf_counter() - t0)
            top = np.argsort(-scores)[: args.k]
            top_hits[m.name, q] = [(int(j), float(scores[j])) for j in top]
        m.release()
    pool, results = {}, []
    for q in queries:
        per_method = [top_hits[m.name, q] for m in methods]
        results.append((q, per_method))
        pool[q] = sorted({rel[j] for hits in per_method for j, _ in hits})
    (OUT / "pool.json").write_text(json.dumps(pool, indent=1))

    print(f"\n{'method':38s} {'query ms':>8s}  P@{args.k} (judged queries)")
    for i, m in enumerate(methods):
        precisions = [sum(rel[j] in judgements[q] for j, _ in results[n][1][i]) / args.k
                      for n, (q, _) in enumerate(results) if q in judgements]
        p_at_k = f"{np.mean(precisions):.2f} ({len(precisions)})" if precisions else "-"
        print(f"{m.name:38s} {np.median(latency[m.name]) * 1e3:8.0f}  {p_at_k}")

    write_html(base, methods, results, rel, captions, judgements)
    write_sheets(base, methods, results)


def write_html(base, methods, results, rel, captions, judgements) -> None:
    sections = []
    for q, per_method in results:
        blocks = ""
        for m, hits in zip(methods, per_method):
            cells = "".join(
                f'<figure class="{"ok" if rel[j] in judgements.get(q, []) else ""}">'
                f'<img src="data:image/jpeg;base64,{spike.thumb_b64(base.rows[j], base.out)}" title="{html.escape(captions[j])}">'
                f'<figcaption>{s:.3f} {html.escape(captions[j][:80])}</figcaption></figure>' for j, s in hits)
            blocks += f"<h3>{html.escape(m.name)}</h3><div class=grid>{cells}</div>"
        sections.append(f"<section><h2>{html.escape(q)}</h2>{blocks}</section>")
    (OUT / "compare.html").write_text(
        "<!doctype html><meta charset=utf-8><title>Model comparison</title><style>"
        "body{font:14px system-ui;margin:16px;background:#fff;color:#111}"
        "section{border-top:1px solid #ddd;padding-top:8px;margin-top:16px}h3{font-size:13px;color:#555;margin:8px 0 4px}"
        ".grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:8px}"
        "figure{margin:0}figure.ok img{outline:3px solid #2a9d4b}"
        "img{width:100%;aspect-ratio:1;object-fit:cover;border-radius:4px}"
        "figcaption{font-size:10px;color:#555;line-height:1.3}"
        f"</style><h1>Model comparison · {len(rel)} photos</h1>{''.join(sections)}")
    print(f"report: {OUT / 'compare.html'}")


def write_sheets(base, methods, results, per_sheet: int = 3) -> None:
    """One sheet per few queries, one row per method, for review as plain images."""
    cell, label_h = 120, 20
    sheets = OUT / "compare-sheets"
    sheets.mkdir(exist_ok=True)
    for n in range(0, len(results), per_sheet):
        chunk = results[n:n + per_sheet]
        cols = max(len(h) for _, per in chunk for h in per)
        sheet = Image.new("RGB", (cols * cell, len(chunk) * len(methods) * (cell + label_h)), "white")
        draw = ImageDraw.Draw(sheet)
        y = 0
        for q, per_method in chunk:
            for m, hits in zip(methods, per_method):
                draw.text((4, y + 3), f"{q}  —  {m.name}", fill="black", font_size=14)
                for c, (j, _) in enumerate(hits):
                    thumb = Image.open(base.out / base.rows[j]["thumb"])
                    sheet.paste(ImageOps.fit(thumb, (cell - 4, cell - 4)), (c * cell + 2, y + label_h))
                y += cell + label_h
        sheet.save(sheets / f"sheet{n // per_sheet + 1:02d}.jpg", quality=80)
    print(f"contact sheets: {sheets}")


if __name__ == "__main__":
    main()
