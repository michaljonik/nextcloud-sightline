"""Effect of a decoder that ignores EXIF orientation: embed portrait photos sideways and re-score.

Every portrait photo in the judged set is embedded as a phone stores it with Orientation 6
(upright image rotated 90° counter-clockwise) or Orientation 8 (rotated clockwise); all other
photos keep their vectors. Scores against out/judgements.json as in metrics.py.

    uv run rotate_eval.py MODEL [MODEL ...]

Top-8 hits that were never judged count as not relevant, as in metrics.py; the script
reports how many there are.
"""

import argparse
import json
from pathlib import Path

import numpy as np

import spike
from metrics import score

LANGS = {"EN": "queries.txt", "PL": "queries-pl.txt"}


def read_queries(path: str, judg: dict) -> list[str]:
    lines = [q.strip() for q in Path(path).read_text().splitlines() if q.strip() and not q.startswith("#")]
    return [q for q in lines if judg.get(q)]


def main() -> None:
    p = argparse.ArgumentParser()
    p.add_argument("models", nargs="+")
    args = p.parse_args()

    judg = json.loads((spike.OUT_ROOT / "judgements.json").read_text())
    pool = json.loads((spike.OUT_ROOT / "judged-pool.json").read_text())

    for m in args.models:
        spike.MODEL = m
        rows = json.loads((spike.out_dir() / "index.json").read_text())["rows"]
        root = Path(rows[0]["path"]).parent
        rel = [str(Path(r["path"]).relative_to(root)) for r in rows]
        stored = np.load(spike.out_dir() / "vectors.npy")
        cfg = json.loads((spike.model_dir() / "visual" / "preprocess_cfg.json").read_text())
        visual = spike.session("visual", "cpu")

        def embed(img):
            feed = {visual.get_inputs()[0].name: spike.preprocess(img, cfg)}
            return spike.normalize(spike.run_embedding(visual, feed))

        portrait, upright, rot6, rot8 = [], [], [], []
        for i, r in enumerate(rows):
            img = spike.to_preview(spike.load_image(Path(r["path"])))
            if img.height <= img.width:
                continue
            portrait.append(i)
            upright.append(embed(img))
            rot6.append(embed(img.rotate(90, expand=True)))
            rot8.append(embed(img.rotate(-90, expand=True)))
        upright, rot6, rot8 = np.stack(upright), np.stack(rot6), np.stack(rot8)
        portrait_names = {rel[i] for i in portrait}

        variants = {}
        for name, vecs in (("upright", upright), ("orientation 6", rot6), ("orientation 8", rot8)):
            v = stored.copy()
            v[portrait] = vecs
            variants[name] = v

        print(f"\n## {m}")
        print(f"{len(portrait)} portrait photos of {len(rows)}; "
              f"recomputed upright vs stored cosine min {np.min(np.sum(upright * stored[portrait], 1)):.5f}")
        for name, vecs in (("orientation 6", rot6), ("orientation 8", rot8)):
            cos = np.sum(upright * vecs, 1)
            print(f"cosine upright vs {name}: median {np.median(cos):.3f}, "
                  f"p10 {np.percentile(cos, 10):.3f}, min {cos.min():.3f}")

        text = spike.TextEncoder("cpu")
        for lang, qfile in LANGS.items():
            queries = read_queries(qfile, judg)
            affected = [k for k, q in enumerate(queries) if set(judg[q]) & portrait_names]
            qvecs = np.stack([text.encode(q) for q in queries])
            res = {}
            for name, v in variants.items():
                orders = [np.argsort(-(v @ qv)) for qv in qvecs]
                s = np.array([score(o, rel, set(judg[q])) for o, q in zip(orders, queries)])
                port_hits = sum(sum(rel[j] in portrait_names and rel[j] in judg[q] for j in o[:8])
                                for o, q in zip(orders, queries))
                unjudged = sum(sum(rel[j] not in pool[q] for j in o[:8]) for o, q in zip(orders, queries))
                res[name] = (s, port_hits, unjudged)
            base = res["upright"][0]
            print(f"\n{lang}: {len(queries)} queries, {len(affected)} with a relevant portrait photo")
            print(f"{'variant':14s} nP@8 all  MRR all  nP@8 affected  MRR affected  "
                  f"worse/better  portrait hits in top 8  unjudged in top 8")
            for name, (s, port_hits, unjudged) in res.items():
                worse = (s[:, 0] < base[:, 0] - 1e-9).sum()
                better = (s[:, 0] > base[:, 0] + 1e-9).sum()
                print(f"{name:14s} {s[:, 0].mean():.3f}     {s[:, 1].mean():.3f}    "
                      f"{s[affected, 0].mean():.3f}          {s[affected, 1].mean():.3f}         "
                      f"{worse:2d} / {better:2d}       {port_hits:3d}                     {unjudged:3d}")


if __name__ == "__main__":
    main()
