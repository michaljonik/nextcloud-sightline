# SPDX-FileCopyrightText: 2026 Michal Jonik
# SPDX-License-Identifier: AGPL-3.0-or-later

"""Lay out jinaai/jina-clip-v2's ONNX export like an Immich export.

The repo ships one graph that takes input_ids and pixel_values together. spike.py times the
image and text sides separately, so this cuts the graph into visual/ and textual/ parts.
The weights are not copied: each part keeps the original external-data offsets and
hard-links onnx/model.onnx_data (ONNX Runtime rejects symlinks that leave the model folder).

    uv run import_jina.py models/jina-clip-v2
"""

import json
import sys
from pathlib import Path

import onnx
from onnx.utils import Extractor

# The model card prefixes queries with its retrieval.query prompt, "Represent the query for
# retrieving evidence documents: ". On the stock set that scored ~4 points nP@8 lower than
# plain queries in both languages (docs/measurements/2026-10-25-jina-clip-v2-m4.md).
QUERY_PREFIX = ""

root = Path(sys.argv[1])
graph = onnx.load(str(root / "onnx" / "model.onnx"), load_external_data=False)
outputs = [o.name for o in graph.graph.output]
print("outputs:", outputs)
extractor = Extractor(graph)

for part, input_name, kind in (("visual", "pixel_values", "image"), ("textual", "input_ids", "text")):
    # The l2-normalised outputs are named like "l2norm_image_embeddings".
    output = next(o for o in outputs if kind in o and "norm" in o)
    (root / part).mkdir(exist_ok=True)
    onnx.save(extractor.extract_model([input_name], [output]), str(root / part / "model.onnx"))
    link = root / part / "model.onnx_data"
    if link.is_symlink():
        link.unlink()
    if not link.exists():
        link.hardlink_to(root / "onnx" / "model.onnx_data")
    print(f"{part}: {input_name} -> {output}")

for name in ("tokenizer.json", "tokenizer_config.json"):
    link = root / "textual" / name
    if not link.exists():
        link.symlink_to(f"../{name}")

pre = json.loads((root / "preprocessor_config.json").read_text())
(root / "visual" / "preprocess_cfg.json").write_text(json.dumps({
    "size": pre["size"], "mean": pre["mean"], "std": pre["std"],
    "interpolation": pre["interpolation"], "resize_mode": pre["resize_mode"],
}))
hf_config = root / "hf_config.json"
if not hf_config.exists():
    (root / "config.json").rename(hf_config)
# No attention-mask input in this graph, so queries must not be padded.
(root / "config.json").write_text(json.dumps({
    "text_cfg": {"context_length": 512, "pad": False, "prefix": QUERY_PREFIX},
}))
print(f"laid out {root}")
