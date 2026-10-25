"""Rough model spike: embed a few hundred local photos and run text queries against them.

Not production code. It answers two questions on the dev machine before anything is built:
does the model find the right photos for plain English queries, and roughly how long do
decoding, preview downscaling and encoding take per photo.

Usage:
    uv run spike.py prepare /path/to/full-size /path/to/copies   # optional, 1024px copies
    uv run spike.py index /path/to/photos --limit 300
    uv run spike.py query "dog in the forest" "birthday cake" ...
    uv run spike.py query --file queries.txt

The model is an Immich ONNX export (visual/ + textual/ + tokenizer), the layout Sightline
plans to support. Outputs (embeddings, thumbnails, HTML report) go to out/, which is not
committed because it contains private photos.
"""

from __future__ import annotations

import argparse
import base64
import html
import io
import json
import random
import resource
import string
import subprocess
import sys
import time
from pathlib import Path

import numpy as np
import onnxruntime as ort
from PIL import Image, ImageDraw, ImageOps
from pillow_heif import register_heif_opener
from tokenizers import Tokenizer

register_heif_opener()

HERE = Path(__file__).parent
MODELS = HERE / "models"
OUT_ROOT = HERE / "out"

# Selected with --model; any Immich-style export (visual/ + textual/ + config.json) works.
MODEL = "ViT-B-16-SigLIP2__webli"


def model_dir() -> Path:
    return MODELS / MODEL


def out_dir() -> Path:
    return OUT_ROOT / MODEL

# Same edge Sightline will request from IPreview (see CLAUDE.md, decision 4).
PREVIEW_EDGE = 1024
THUMB_EDGE = 256

IMAGE_EXT = {".jpg", ".jpeg", ".png", ".heic", ".heif", ".webp", ".tif", ".tiff"}
RAW_EXT = {".nef", ".dng", ".cr2", ".cr3", ".arw", ".orf", ".rw2", ".raf"}


def rss_mb() -> float:
    # ru_maxrss is bytes on macOS, kilobytes on Linux.
    peak = resource.getrusage(resource.RUSAGE_SELF).ru_maxrss
    return peak / 1e6 if sys.platform == "darwin" else peak / 1e3


THREADS = 0  # 0 = ONNX Runtime default (all physical cores)


def session(part: str, provider: str) -> ort.InferenceSession:
    providers = ["CoreMLExecutionProvider", "CPUExecutionProvider"] if provider == "coreml" else ["CPUExecutionProvider"]
    opts = ort.SessionOptions()
    opts.intra_op_num_threads = THREADS
    return ort.InferenceSession(str(model_dir() / part / "model.onnx"), opts, providers=providers)


def run_embedding(sess: ort.InferenceSession, feed: dict) -> np.ndarray:
    """Return the pooled embedding: Immich exports call it "embedding", HF exports "pooler_output"."""
    names = [o.name for o in sess.get_outputs()]
    name = next((n for n in ("embedding", "image_embeds", "text_embeds", "pooler_output") if n in names), names[0])
    return sess.run([name], feed)[0][0]


# --- image side -------------------------------------------------------------------------


def load_image(path: Path) -> Image.Image:
    """Decode a photo. RAW files use the embedded JPEG, as a RAW preview path would."""
    if path.suffix.lower() in RAW_EXT:
        for tag in ("-JpgFromRaw", "-PreviewImage"):
            data = subprocess.run(["exiftool", "-b", tag, str(path)], capture_output=True).stdout
            if data:
                return ImageOps.exif_transpose(Image.open(io.BytesIO(data)))
        raise ValueError("no embedded JPEG")
    return ImageOps.exif_transpose(Image.open(path))


def to_preview(img: Image.Image) -> Image.Image:
    """Approximate what IPreview hands over: RGB, long edge at most PREVIEW_EDGE."""
    img = img.convert("RGB")
    img.thumbnail((PREVIEW_EDGE, PREVIEW_EDGE), Image.Resampling.BICUBIC)
    return img


def preprocess(img: Image.Image, cfg: dict) -> np.ndarray:
    # preprocess_cfg.json: "squash" resizes without cropping (SigLIP); "shortest" resizes
    # the short side and centre-crops (OpenAI-style CLIP).
    size = cfg["size"]
    w, h = size if isinstance(size, list) else (size, size)
    resample = Image.Resampling.BILINEAR if cfg.get("interpolation") == "bilinear" else Image.Resampling.BICUBIC
    if cfg.get("resize_mode") == "squash":
        img = img.resize((w, h), resample)
    else:
        img = ImageOps.fit(img, (w, h), resample)
    x = np.asarray(img, dtype=np.float32) / 255.0
    x = (x - np.array(cfg["mean"], dtype=np.float32)) / np.array(cfg["std"], dtype=np.float32)
    return x.transpose(2, 0, 1)[None]


def normalize(v: np.ndarray) -> np.ndarray:
    return v / np.linalg.norm(v, axis=-1, keepdims=True)


def find_photos(root: Path) -> list[Path]:
    # Skip hidden folders and macOS resource forks (._name.jpg, .AppleDouble/).
    return sorted(
        p for p in root.rglob("*")
        if p.is_file() and p.suffix.lower() in IMAGE_EXT | RAW_EXT
        and not any(part.startswith(".") for part in p.relative_to(root).parts)
    )


def pick_files(root: Path, limit: int, seed: int) -> list[Path]:
    files = find_photos(root)
    random.Random(seed).shuffle(files)
    return sorted(files[:limit])


def cmd_prepare(args: argparse.Namespace) -> None:
    """Write PREVIEW_EDGE copies of full-size photos and time what that costs.

    Roughly the work Nextcloud does once per photo for a preview, so the timings say
    something about first-indexing cost on top of the encoder.
    """
    src, dst = Path(args.src).expanduser(), Path(args.dst).expanduser()
    files = find_photos(src)
    print(f"{len(files)} photos in {src}")
    timings = []
    for i, path in enumerate(files):
        target = (dst / path.relative_to(src)).with_suffix(".jpg")
        target.parent.mkdir(parents=True, exist_ok=True)
        t0 = time.perf_counter()
        img = load_image(path)
        t1 = time.perf_counter()
        preview = to_preview(img)
        t2 = time.perf_counter()
        preview.save(target, quality=90)
        t3 = time.perf_counter()
        timings.append({"path": str(path), "megapixels": round(img.width * img.height / 1e6, 1),
                        "decode_ms": (t1 - t0) * 1e3, "resize_ms": (t2 - t1) * 1e3, "save_ms": (t3 - t2) * 1e3})
        if (i + 1) % 100 == 0:
            print(f"  {i + 1}/{len(files)}")
    (dst / "prepare.json").write_text(json.dumps(timings, indent=1))
    mp = np.array([t["megapixels"] for t in timings])
    print(f"megapixels median {np.median(mp):.1f}, max {mp.max():.1f}")
    for key in ("decode_ms", "resize_ms", "save_ms"):
        v = np.array([t[key] for t in timings])
        print(f"  {key:9s} median {np.median(v):6.1f} ms  p90 {np.percentile(v, 90):6.1f} ms")
    total = sum(t["decode_ms"] + t["resize_ms"] + t["save_ms"] for t in timings) / 1e3
    print(f"  total {total:.1f}s -> {len(timings) / total:.1f} photos/s, one process")


def cmd_index(args: argparse.Namespace) -> None:
    files = pick_files(Path(args.folder).expanduser(), args.limit, args.seed)
    print(f"{len(files)} files selected")

    cfg = json.loads((model_dir() / "visual" / "preprocess_cfg.json").read_text())
    t0 = time.perf_counter()
    visual = session("visual", args.provider)
    load_s = time.perf_counter() - t0
    print(f"visual model loaded in {load_s:.1f}s, peak RSS {rss_mb():.0f} MB")

    thumbs = out_dir() / "thumbs"
    thumbs.mkdir(parents=True, exist_ok=True)
    rows, vectors, failures = [], [], []

    for i, path in enumerate(files):
        try:
            t0 = time.perf_counter()
            img = load_image(path)
            t1 = time.perf_counter()
            preview = to_preview(img)
            t2 = time.perf_counter()
            vec = run_embedding(visual, {visual.get_inputs()[0].name: preprocess(preview, cfg)})
            t3 = time.perf_counter()
        except Exception as e:  # noqa: BLE001 - spike: record and move on
            failures.append({"path": str(path), "error": str(e)})
            continue

        if args.timing_only:
            rows.append({"ext": path.suffix.lower(), "decode_ms": (t1 - t0) * 1e3,
                         "preview_ms": (t2 - t1) * 1e3, "encode_ms": (t3 - t2) * 1e3})
            vectors.append(vec)
            continue
        thumb = preview.copy()
        thumb.thumbnail((THUMB_EDGE, THUMB_EDGE))
        thumb_name = f"{len(rows):05d}.jpg"
        thumb.save(thumbs / thumb_name, quality=80)

        vectors.append(vec)
        rows.append({
            "path": str(path),
            "thumb": f"thumbs/{thumb_name}",
            "ext": path.suffix.lower(),
            "megapixels": round(img.width * img.height / 1e6, 1),
            "decode_ms": (t1 - t0) * 1e3,
            "preview_ms": (t2 - t1) * 1e3,
            "encode_ms": (t3 - t2) * 1e3,
        })
        if (i + 1) % 50 == 0:
            print(f"  {i + 1}/{len(files)}")

    if not args.timing_only:
        np.save(out_dir() / "vectors.npy", normalize(np.stack(vectors)).astype(np.float32))
        (out_dir() / "index.json").write_text(json.dumps({"rows": rows, "failures": failures}, indent=1))

    def stats(key: str, subset: list[dict]) -> str:
        v = np.array([r[key] for r in subset])
        return f"median {np.median(v):6.1f} ms  p90 {np.percentile(v, 90):6.1f} ms"

    print(f"\nindexed {len(rows)}, failed {len(failures)}, provider {args.provider}")
    print(f"peak RSS {rss_mb():.0f} MB")
    for key in ("decode_ms", "preview_ms", "encode_ms"):
        print(f"  {key:11s} all     {stats(key, rows)}")
    for ext in sorted({r["ext"] for r in rows}):
        subset = [r for r in rows if r["ext"] == ext]
        print(f"  decode_ms   {ext:7s} {stats('decode_ms', subset)}  (n={len(subset)})")
    total = sum(r["decode_ms"] + r["preview_ms"] + r["encode_ms"] for r in rows) / 1e3
    print(f"  total {total:.1f}s -> {len(rows) / total:.1f} photos/s sequential, {THREADS or 'default'} ORT threads")
    for f in failures[:10]:
        print(f"  FAILED {f['path']}: {f['error']}")


# --- text side --------------------------------------------------------------------------


class TextEncoder:
    """Mirrors Immich's OpenClipTextualEncoder: canonicalize, pad/truncate to context length."""

    def __init__(self, provider: str):
        config = json.loads((model_dir() / "config.json").read_text())
        text_cfg = config["text_cfg"]
        self.canonicalize = text_cfg.get("tokenizer_kwargs", {}).get("clean") == "canonicalize"
        # Instruction-tuned text towers (jina-clip-v2) expect a task prefix on queries.
        self.prefix = text_cfg.get("prefix", "")
        self.tokenizer = Tokenizer.from_file(str(model_dir() / "textual" / "tokenizer.json"))
        if text_cfg.get("pad", True):
            tok_cfg = json.loads((model_dir() / "textual" / "tokenizer_config.json").read_text())
            pad = tok_cfg["pad_token"]
            self.tokenizer.enable_padding(length=text_cfg.get("context_length", 77), pad_token=pad,
                                          pad_id=self.tokenizer.token_to_id(pad))
        self.tokenizer.enable_truncation(max_length=text_cfg.get("context_length", 77))
        self.session = session("textual", provider)

    def encode(self, text: str) -> np.ndarray:
        text = " ".join(text.split())
        if self.canonicalize:
            text = text.translate(str.maketrans("", "", string.punctuation)).lower()
        enc = self.tokenizer.encode(self.prefix + text)
        feed = {}
        for inp in self.session.get_inputs():
            dtype = np.int64 if "int64" in inp.type else np.int32
            values = enc.attention_mask if "mask" in inp.name else enc.ids
            feed[inp.name] = np.array([values], dtype=dtype)
        return normalize(run_embedding(self.session, feed))


def cmd_query(args: argparse.Namespace) -> None:
    queries = list(args.queries)
    if args.file:
        queries += [q.strip() for q in Path(args.file).read_text().splitlines()
                    if q.strip() and not q.startswith("#")]

    index = json.loads((out_dir() / "index.json").read_text())
    rows, vectors = index["rows"], np.load(out_dir() / "vectors.npy")

    t0 = time.perf_counter()
    text = TextEncoder(args.provider)
    print(f"text model loaded in {time.perf_counter() - t0:.1f}s, peak RSS {rss_mb():.0f} MB")

    sections, latencies, results = [], [], []
    for q in queries:
        t0 = time.perf_counter()
        qv = text.encode(q)
        latencies.append((time.perf_counter() - t0) * 1e3)
        scores = vectors @ qv
        top = np.argsort(-scores)[: args.k]
        print(f"\n{q!r}  ({latencies[-1]:.0f} ms)")
        for j in top:
            print(f"  {scores[j]:.3f}  {rows[j]['path']}")
        results.append((q, [(rows[j], float(scores[j])) for j in top]))
        # Thumbnails are inlined so the report is a single self-contained file.
        cells = "".join(
            f'<figure><img src="data:image/jpeg;base64,{thumb_b64(rows[j])}">'
            f'<figcaption>{scores[j]:.3f} · {html.escape(Path(rows[j]["path"]).name)}</figcaption></figure>'
            for j in top)
        sections.append(f"<h2>{html.escape(q)}</h2><div class=grid>{cells}</div>")

    print(f"\ntext encode median {np.median(latencies):.0f} ms over {len(latencies)} queries")
    (out_dir() / "report.html").write_text(
        "<!doctype html><meta charset=utf-8><title>Sightline model spike</title><style>"
        "body{font:14px system-ui;margin:16px;background:#fff;color:#111}"
        ".grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:8px}"
        "figure{margin:0}img{width:100%;aspect-ratio:1;object-fit:cover;border-radius:4px}"
        "figcaption{font-size:11px;color:#555;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}"
        f"</style><h1>Sightline model spike · {len(rows)} photos</h1>{''.join(sections)}")
    print(f"report: {out_dir() / 'report.html'}")
    write_sheets(results)


def thumb_b64(row: dict, base: Path | None = None) -> str:
    return base64.b64encode(((base or out_dir()) / row["thumb"]).read_bytes()).decode()


def write_sheets(results: list[tuple[str, list[tuple[dict, float]]]], per_sheet: int = 5) -> None:
    """Contact sheets (one row per query) for reviewing results as plain images."""
    cell, label_h = 150, 22
    sheets = out_dir() / "sheets"
    sheets.mkdir(exist_ok=True)
    for n in range(0, len(results), per_sheet):
        chunk = results[n:n + per_sheet]
        cols = max(len(hits) for _, hits in chunk)
        sheet = Image.new("RGB", (cols * cell, len(chunk) * (cell + label_h)), "white")
        draw = ImageDraw.Draw(sheet)
        for r, (q, hits) in enumerate(chunk):
            y = r * (cell + label_h)
            draw.text((4, y + 4), q, fill="black", font_size=16)
            for c, (row, score) in enumerate(hits):
                img = ImageOps.fit(Image.open(out_dir() / row["thumb"]), (cell - 4, cell - 4))
                sheet.paste(img, (c * cell + 2, y + label_h))
                draw.text((c * cell + 6, y + label_h + 4), f"{score:.3f}", fill="yellow", font_size=14)
        sheet.save(sheets / f"sheet{n // per_sheet + 1}.jpg", quality=85)
    print(f"contact sheets: {sheets}")


def main() -> None:
    global THREADS, MODEL
    p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    p.add_argument("--provider", choices=["cpu", "coreml"], default="cpu")
    p.add_argument("--model", default=MODEL, help="folder name under models/")
    p.add_argument("--threads", type=int, default=0, help="ONNX Runtime intra-op threads, 0 = default")
    sub = p.add_subparsers(dest="cmd", required=True)

    pp = sub.add_parser("prepare")
    pp.add_argument("src")
    pp.add_argument("dst")
    pp.set_defaults(func=cmd_prepare)

    pi = sub.add_parser("index")
    pi.add_argument("folder")
    pi.add_argument("--limit", type=int, default=300)
    pi.add_argument("--seed", type=int, default=0)
    pi.add_argument("--timing-only", action="store_true", help="measure only, do not overwrite the index")
    pi.set_defaults(func=cmd_index)

    pq = sub.add_parser("query")
    pq.add_argument("queries", nargs="*")
    pq.add_argument("--file")
    pq.add_argument("-k", type=int, default=8)
    pq.set_defaults(func=cmd_query)

    args = p.parse_args()
    THREADS, MODEL = args.threads, args.model
    args.func(args)


if __name__ == "__main__":
    main()
