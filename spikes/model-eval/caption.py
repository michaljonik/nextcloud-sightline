# SPDX-FileCopyrightText: 2026 Michal Jonik
# SPDX-License-Identifier: AGPL-3.0-or-later

"""Caption photos with Moondream2 for the caption-then-text-search comparison.

Moondream2's remote code needs transformers 4.x, so this runs in its own environment:

    uv run --no-project --python 3.12 --with "transformers>=4.51,<4.56" --with torch \
        --with einops --with accelerate --with pillow caption.py ~/photos --device mps

Writes out/captions.json: {relative path: {"caption": ..., "seconds": ...}}. Resumable: photos
already in the file are skipped.
"""

from __future__ import annotations

import argparse
import json
import sys
import time
from pathlib import Path

import torch
from PIL import Image
from transformers import AutoModelForCausalLM

REVISION = "2025-06-21"  # pin: the repo is updated in place
OUT = Path(__file__).parent / "out"


def main() -> None:
    p = argparse.ArgumentParser()
    p.add_argument("folder")
    p.add_argument("--device", default="mps", choices=["mps", "cpu"])
    p.add_argument("--length", default="normal", choices=["short", "normal"])
    p.add_argument("--dtype", default="float16", choices=["float16", "bfloat16", "float32"])
    p.add_argument("--limit", type=int, default=0)
    p.add_argument("--output", default=str(OUT / "captions.json"))
    args = p.parse_args()

    root = Path(args.folder).expanduser()
    files = sorted(p for p in root.rglob("*.jpg") if not any(s.startswith(".") for s in p.relative_to(root).parts))
    if args.limit:
        files = files[: args.limit]

    out_path = Path(args.output)
    done = json.loads(out_path.read_text()) if out_path.exists() else {}

    t0 = time.perf_counter()
    model = AutoModelForCausalLM.from_pretrained("vikhyatk/moondream2", revision=REVISION,
                                                 trust_remote_code=True, device_map={"": args.device},
                                                 torch_dtype=getattr(torch, args.dtype))
    print(f"model loaded in {time.perf_counter() - t0:.1f}s on {args.device}, {args.dtype}", flush=True)

    todo = [f for f in files if str(f.relative_to(root)) not in done]
    for i, path in enumerate(todo):
        img = Image.open(path).convert("RGB")
        t0 = time.perf_counter()
        caption = model.caption(img, length=args.length)["caption"].strip()
        if args.device == "mps":
            torch.mps.synchronize()
        done[str(path.relative_to(root))] = {"caption": caption, "seconds": time.perf_counter() - t0}
        if (i + 1) % 25 == 0 or i + 1 == len(todo):
            out_path.write_text(json.dumps(done, indent=1))
            secs = [v["seconds"] for v in done.values()]
            print(f"  {i + 1}/{len(todo)}  median {sorted(secs)[len(secs) // 2]:.2f}s/photo", flush=True)
    out_path.write_text(json.dumps(done, indent=1))


if __name__ == "__main__":
    sys.exit(main())
