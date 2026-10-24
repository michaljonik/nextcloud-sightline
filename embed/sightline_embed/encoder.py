"""Image and text encoders for an Immich-style ONNX export.

Layout (as published under huggingface.co/immich-app):

    <model>/config.json                  embed_dim, text_cfg (context_length, tokenizer_kwargs)
    <model>/visual/model.onnx
    <model>/visual/preprocess_cfg.json   size, mean, std, interpolation, resize_mode
    <model>/textual/model.onnx
    <model>/textual/tokenizer.json, tokenizer_config.json

Any CLIP-family model in this layout works; nothing here assumes a specific one.
"""

from __future__ import annotations

import io
import json
import string
from pathlib import Path

import numpy as np
import onnxruntime as ort
from PIL import Image, ImageOps
from pillow_heif import register_heif_opener
from tokenizers import Tokenizer

register_heif_opener()

# Exports name the pooled output differently: Immich "embedding", HF "pooler_output".
OUTPUT_NAMES = ("embedding", "image_embeds", "text_embeds", "pooler_output")


def _session(path: Path, threads: int) -> ort.InferenceSession:
    opts = ort.SessionOptions()
    opts.intra_op_num_threads = threads
    return ort.InferenceSession(str(path), opts, providers=["CPUExecutionProvider"])


def _run(sess: ort.InferenceSession, feed: dict) -> np.ndarray:
    names = [o.name for o in sess.get_outputs()]
    name = next((n for n in OUTPUT_NAMES if n in names), names[0])
    v = sess.run([name], feed)[0][0].astype(np.float32)
    return v / np.linalg.norm(v)


class Encoder:
    def __init__(self, model_dir: Path, threads: int = 0):
        self.model_id = model_dir.name
        config = json.loads((model_dir / "config.json").read_text())
        self.dims = int(config["embed_dim"])
        self._visual = _session(model_dir / "visual" / "model.onnx", threads)
        self._pre = json.loads((model_dir / "visual" / "preprocess_cfg.json").read_text())

        text_cfg = config["text_cfg"]
        context = text_cfg.get("context_length", 77)
        self._canonicalize = text_cfg.get("tokenizer_kwargs", {}).get("clean") == "canonicalize"
        pad = json.loads((model_dir / "textual" / "tokenizer_config.json").read_text())["pad_token"]
        self._tokenizer = Tokenizer.from_file(str(model_dir / "textual" / "tokenizer.json"))
        self._tokenizer.enable_padding(length=context, pad_token=pad, pad_id=self._tokenizer.token_to_id(pad))
        self._tokenizer.enable_truncation(max_length=context)
        self._textual = _session(model_dir / "textual" / "model.onnx", threads)

    def encode_image(self, data: bytes) -> np.ndarray:
        img = ImageOps.exif_transpose(Image.open(io.BytesIO(data))).convert("RGB")
        return _run(self._visual, {self._visual.get_inputs()[0].name: self._preprocess(img)})

    def encode_text(self, text: str) -> np.ndarray:
        text = " ".join(text.split())
        if self._canonicalize:
            text = text.translate(str.maketrans("", "", string.punctuation)).lower()
        enc = self._tokenizer.encode(text)
        feed = {}
        for inp in self._textual.get_inputs():
            dtype = np.int64 if "int64" in inp.type else np.int32
            feed[inp.name] = np.array([enc.attention_mask if "mask" in inp.name else enc.ids], dtype=dtype)
        return _run(self._textual, feed)

    def _preprocess(self, img: Image.Image) -> np.ndarray:
        # "squash" resizes without cropping (SigLIP); "shortest" resizes the short side and
        # centre-crops (OpenAI-style CLIP).
        cfg = self._pre
        size = cfg["size"]
        w, h = size if isinstance(size, list) else (size, size)
        resample = Image.Resampling.BILINEAR if cfg.get("interpolation") == "bilinear" else Image.Resampling.BICUBIC
        img = img.resize((w, h), resample) if cfg.get("resize_mode") == "squash" else ImageOps.fit(img, (w, h), resample)
        x = np.asarray(img, dtype=np.float32) / 255.0
        x = (x - np.array(cfg["mean"], dtype=np.float32)) / np.array(cfg["std"], dtype=np.float32)
        return x.transpose(2, 0, 1)[None]
