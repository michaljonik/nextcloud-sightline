"""Finding the model export: a local folder, or a Hugging Face repo downloaded once.

Immich publishes CLIP-family models in the layout encoder.py reads, under
huggingface.co/immich-app. Only the ONNX files are fetched, not the RKNN exports for
Rockchip NPUs that the same repos carry.
"""

from __future__ import annotations

from pathlib import Path

FILES = [
    "config.json",
    "visual/model.onnx",
    "visual/model.onnx_data",  # external weights, only in large exports
    "visual/preprocess_cfg.json",
    "textual/model.onnx",
    "textual/model.onnx_data",
    "textual/tokenizer.json",
    "textual/tokenizer_config.json",
    "textual/special_tokens_map.json",
]


def resolve(model_dir: str | None, model: str | None, cache: Path) -> Path:
    """`model_dir` wins; otherwise download `model` ("org/name") into `cache`/name."""
    if model_dir:
        return Path(model_dir)
    if not model:
        raise SystemExit("set SIGHTLINE_MODEL (e.g. immich-app/XLM-Roberta-Base-ViT-B-32__laion5b_s13b_b90k) "
                         "or SIGHTLINE_MODEL_DIR")
    target = cache / model.split("/")[-1]
    if not (target / "config.json").exists():
        from huggingface_hub import snapshot_download

        print(f"downloading {model} to {target}", flush=True)
        snapshot_download(model, local_dir=target, allow_patterns=FILES)
    return target
