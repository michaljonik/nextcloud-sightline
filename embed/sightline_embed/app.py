# SPDX-FileCopyrightText: 2026 Michal Jonik
# SPDX-License-Identifier: AGPL-3.0-or-later

"""HTTP API. The service knows only file IDs, storage IDs and vectors: no users, no paths,
no Nextcloud credentials. Nextcloud pushes images in and checks permissions on results.

    PUT    /files/{fileid}?storage_id=N   body: image bytes (JPEG/WebP/AVIF/PNG/HEIC)
    PATCH  /files/{fileid}                {"storage_id": N}   (file moved to another storage)
    DELETE /files/{fileid}
    DELETE /files                         (every vector of every model: Sightline is removed)
    DELETE /models/{model}                (an earlier model's vectors, after a model change)
    POST   /search                        {"query", "storage_ids", "offset", "limit", "model"?}
    GET    /health

Every request needs `Authorization: Bearer $SIGHTLINE_TOKEN`.

After a model change, the previous model's text encoder stays loaded (if its export is
still on disk) so Nextcloud can keep searching its vectors with `"model"` until the
current model has encoded every photo.
"""

from __future__ import annotations

import hmac
import os
import time
from pathlib import Path
from typing import Protocol

import numpy as np
import uvicorn
from fastapi import Depends, FastAPI, Header, HTTPException, Request
from pydantic import BaseModel, Field

from . import models
from .store import MAX_K, VectorStore


class TextEncoder(Protocol):
    model_id: str
    dims: int

    def encode_text(self, text: str) -> np.ndarray: ...


class Encoder(TextEncoder, Protocol):
    def encode_image(self, data: bytes) -> np.ndarray: ...


class Move(BaseModel):
    storage_id: int


class Search(BaseModel):
    query: str = Field(min_length=1, max_length=500)
    storage_ids: list[int] = Field(max_length=10_000)
    offset: int = Field(0, ge=0)
    limit: int = Field(20, ge=1, le=500)
    # Which model's vectors to rank; default: the current model.
    model: str | None = Field(None, max_length=200)


def create_app(encoder: Encoder, store: VectorStore, token: str,
               previous: dict[str, TextEncoder] | None = None) -> FastAPI:
    """`previous`: text encoders of earlier models whose vectors are still held, by model ID."""
    if not token:
        raise ValueError("an empty token would let anyone read and write vectors")
    previous = dict(previous or {})
    expected = f"Bearer {token}".encode()

    def check_token(authorization: str = Header("")) -> None:
        if not hmac.compare_digest(authorization.encode(), expected):
            raise HTTPException(401)

    app = FastAPI(title="sightline-embed", dependencies=[Depends(check_token)])

    @app.get("/health")
    def health() -> dict:
        models = [{"model": m["model"], "dims": m["dims"], "count": m["count"],
                   "searchable": m["model"] == encoder.model_id or m["model"] in previous}
                  for m in store.models()]
        return {"model": encoder.model_id, "dims": encoder.dims, "count": store.count(), "models": models}

    @app.put("/files/{fileid}")
    async def index_file(fileid: int, storage_id: int, request: Request) -> dict:
        data = await request.body()
        if not data:
            raise HTTPException(400, "empty body")
        t0 = time.perf_counter()
        try:
            vector = encoder.encode_image(data)
        except Exception as e:  # undecodable image: tell Nextcloud, do not retry forever
            raise HTTPException(422, f"cannot decode image: {e}")
        store.upsert(fileid, storage_id, vector)
        return {"fileid": fileid, "model": encoder.model_id, "encode_ms": round((time.perf_counter() - t0) * 1e3, 1)}

    @app.patch("/files/{fileid}")
    def move_file(fileid: int, body: Move) -> dict:
        if not store.move(fileid, body.storage_id):
            raise HTTPException(404)
        return {"fileid": fileid}

    @app.delete("/files/{fileid}")
    def delete_file(fileid: int) -> dict:
        return {"fileid": fileid, "deleted": store.delete(fileid)}

    @app.delete("/files")
    def delete_all() -> dict:
        deleted = store.clear()
        previous.clear()
        return {"deleted": deleted}

    @app.delete("/models/{model}")
    def delete_model(model: str) -> dict:
        if model == encoder.model_id:
            raise HTTPException(409, "the current model's vectors cannot be dropped")
        deleted = store.drop(model)
        if deleted is None:
            raise HTTPException(404)
        previous.pop(model, None)
        return {"model": model, "deleted": deleted}

    @app.post("/search")
    def search(body: Search) -> dict:
        model = body.model or encoder.model_id
        text = encoder if model == encoder.model_id else previous.get(model)
        if text is None:
            raise HTTPException(409, f"model {model} is not searchable here")
        t0 = time.perf_counter()
        vector = text.encode_text(body.query)
        t1 = time.perf_counter()
        hits = store.search(vector, body.storage_ids, body.offset + body.limit,
                            None if model == encoder.model_id else model)
        t2 = time.perf_counter()
        page = hits[body.offset:]
        return {
            "model": model,
            "results": [{"fileid": f, "score": round(s, 4)} for f, s in page],
            # Fewer hits than asked for means the ranking is exhausted.
            "exhausted": len(hits) < min(body.offset + body.limit, MAX_K),
            "timing_ms": {"text": round((t1 - t0) * 1e3, 1), "scan": round((t2 - t1) * 1e3, 1)},
        }

    return app


def main() -> None:
    from .encoder import OnnxEncoder

    env = os.environ
    model_dir = models.resolve(env.get("SIGHTLINE_MODEL_DIR"), env.get("SIGHTLINE_MODEL"),
                               Path(env.get("SIGHTLINE_MODELS", "models")))
    token = env.get("SIGHTLINE_TOKEN", "")
    if not token:
        raise SystemExit("set SIGHTLINE_TOKEN to a long random string (also entered in the Nextcloud admin settings)")
    threads = int(env.get("SIGHTLINE_THREADS", "0"))
    encoder = OnnxEncoder(model_dir, threads)
    store = VectorStore(Path(env.get("SIGHTLINE_DB", "sightline.db")), encoder.model_id, encoder.dims, model_dir)
    previous = {}
    for m in store.models():
        if m["model"] == encoder.model_id or m["count"] == 0:
            continue
        # Loaded until Nextcloud has every photo in the current model and drops this one.
        try:
            previous[m["model"]] = OnnxEncoder(Path(m["model_dir"]), threads, text_only=True)
            print(f"previous model {m['model']} answers queries until the switch", flush=True)
        except Exception as e:  # noqa: BLE001 - export gone: search falls back to the current model
            print(f"previous model {m['model']} cannot be loaded ({e}); "
                  f"queries use {encoder.model_id} while it indexes", flush=True)
    uvicorn.run(create_app(encoder, store, token, previous),
                host=env.get("SIGHTLINE_HOST", "127.0.0.1"), port=int(env.get("SIGHTLINE_PORT", "8765")))
