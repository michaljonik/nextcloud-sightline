"""Lay out a Hugging Face (transformers.js-style) SigLIP ONNX export like an Immich export.

onnx-community repos ship onnx/vision_model.onnx + onnx/text_model.onnx with HF configs;
spike.py expects visual/ + textual/ + an OpenCLIP-style config.json. This links the files
into place and writes the small configs spike.py reads.

    uv run import_hf.py models/hf-siglip2-base-patch16-256
"""

import json
import sys
from pathlib import Path

RESAMPLE = {2: "bilinear", 3: "bicubic"}  # PIL codes used in HF preprocessor_config.json

root = Path(sys.argv[1])
hf_config = root / "hf_config.json"
if not hf_config.exists():
    (root / "config.json").rename(hf_config)
pre = json.loads((root / "preprocessor_config.json").read_text())

for part, onnx_name, extra in (("visual", "vision_model.onnx", []),
                               ("textual", "text_model.onnx", ["tokenizer.json", "tokenizer_config.json"])):
    (root / part).mkdir(exist_ok=True)
    for name, target in [("model.onnx", f"../onnx/{onnx_name}")] + [(e, f"../{e}") for e in extra]:
        link = root / part / name
        if not link.exists():
            link.symlink_to(target)

(root / "visual" / "preprocess_cfg.json").write_text(json.dumps({
    "size": [pre["size"]["width"], pre["size"]["height"]],
    "mean": pre["image_mean"], "std": pre["image_std"],
    "interpolation": RESAMPLE.get(pre.get("resample"), "bicubic"),
    "resize_mode": "squash",  # SiglipImageProcessor resizes to a fixed size without cropping
}))
# SigLIP text towers were trained on lower-cased text padded to 64 tokens.
(root / "config.json").write_text(json.dumps({
    "text_cfg": {"context_length": 64, "tokenizer_kwargs": {"clean": "canonicalize"}},
}))
print(f"laid out {root}")
