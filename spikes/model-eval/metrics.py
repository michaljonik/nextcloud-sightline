"""Score methods against out/judgements.json: nP@8, MRR, and wins/losses vs the first model.

    uv run metrics.py --queries queries.txt MODEL [MODEL ...] [--captions out/captions-short.json]

nP@8 = relevant hits in the top 8 / min(8, number of relevant photos); queries with no
relevant photo are skipped.
"""

import argparse
import json
from pathlib import Path

import numpy as np

import spike


def score(order: np.ndarray, rel: list[str], relevant: set[str]) -> tuple[float, float]:
    hits = sum(rel[j] in relevant for j in order[:8])
    first = next(i for i, j in enumerate(order) if rel[j] in relevant)
    return hits / min(8, len(relevant)), 1 / (first + 1)


def main() -> None:
    p = argparse.ArgumentParser()
    p.add_argument("models", nargs="+")
    p.add_argument("--queries", required=True)
    p.add_argument("--captions")
    args = p.parse_args()

    judg = json.loads((spike.OUT_ROOT / "judgements.json").read_text())
    queries = [q.strip() for q in Path(args.queries).read_text().splitlines() if q.strip() and not q.startswith("#")]
    queries = [q for q in queries if judg.get(q)]

    results = {}
    for m in args.models:
        spike.MODEL = m
        rows = json.loads((spike.out_dir() / "index.json").read_text())["rows"]
        root = Path(rows[0]["path"]).parent
        rel = [str(Path(r["path"]).relative_to(root)) for r in rows]
        vectors, text = np.load(spike.out_dir() / "vectors.npy"), spike.TextEncoder("cpu")
        results[m] = np.array([score(np.argsort(-(vectors @ text.encode(q))), rel, set(judg[q])) for q in queries])

    if args.captions:
        from sentence_transformers import SentenceTransformer
        caps = json.loads(Path(args.captions).read_text())
        e5 = SentenceTransformer("intfloat/multilingual-e5-small", device="cpu")
        vectors = e5.encode([f"passage: {caps[r]['caption']}" for r in rel], normalize_embeddings=True)
        results["Moondream2 captions + e5"] = np.array([
            score(np.argsort(-(vectors @ e5.encode(f"query: {q}", normalize_embeddings=True))), rel, set(judg[q]))
            for q in queries])

    base = results[args.models[0]]
    print(f"{len(queries)} queries from {args.queries}")
    print(f"{'method':46s} nP@8   MRR   better/worse vs first")
    for name, a in results.items():
        better, worse = (a[:, 0] > base[:, 0] + 1e-9).sum(), (a[:, 0] < base[:, 0] - 1e-9).sum()
        print(f"{name:46s} {a[:, 0].mean():.3f}  {a[:, 1].mean():.3f}   {better:2d} / {worse:2d}")


if __name__ == "__main__":
    main()
