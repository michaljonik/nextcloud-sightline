# SPDX-FileCopyrightText: 2026 Michal Jonik
# SPDX-License-Identifier: AGPL-3.0-or-later

"""HTTP API. The service knows only file IDs, storage IDs and vectors: no users, no paths,
no Nextcloud credentials. Nextcloud pushes images in and checks permissions on results.

    PUT    /files/{fileid}?storage_id=N   body: image bytes (JPEG/WebP/AVIF/PNG/HEIC)
    PATCH  /files/{fileid}                {"storage_id": N}   (file moved to another storage)
    DELETE /files/{fileid}
    POST   /search                        {"query", "storage_ids", "offset", "limit"}
    GET    /health

Every request needs `Authorization: Bearer $SIGHTLINE_TOKEN`.
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


class Encoder(Protocol):
    model_id: str
    dims: int

    def encode_image(self, data: bytes) -> np.ndarray: ...

    def encode_text(self, text: str) -> np.ndarray: ...


class Move(BaseModel):
    storage_id: int


class Search(BaseModel):
    query: str = Field(min_length=1, max_length=500)
    storage_ids: list[int] = Field(max_length=10_000)
    offset: int = Field(0, ge=0)
    limit: int = Field(20, ge=1, le=500)


def create_app(encoder: Encoder, store: VectorStore, token: str) -> FastAPI:
    if not token:
        raise ValueError("an empty token would let anyone read and write vectors")
    expected = f"Bearer {token}".encode()

    def check_token(authorization: str = Header("")) -> None:
        if not hmac.compare_digest(authorization.encode(), expected):
            raise HTTPException(401)

    app = FastAPI(title="sightline-embed", dependencies=[Depends(check_token)])

    @app.get("/health")
    def health() -> dict:
        return {"model": encoder.model_id, "dims": encoder.dims, "count": store.count()}

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

    @app.post("/search")
    def search(body: Search) -> dict:
        t0 = time.perf_counter()
        vector = encoder.encode_text(body.query)
        t1 = time.perf_counter()
        hits = store.search(vector, body.storage_ids, body.offset + body.limit)
        t2 = time.perf_counter()
        page = hits[body.offset:]
        return {
            "model": encoder.model_id,
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
    encoder = OnnxEncoder(model_dir, int(env.get("SIGHTLINE_THREADS", "0")))
    store = VectorStore(Path(env.get("SIGHTLINE_DB", "sightline.db")), encoder.model_id, encoder.dims)
    uvicorn.run(create_app(encoder, store, token),
                host=env.get("SIGHTLINE_HOST", "127.0.0.1"), port=int(env.get("SIGHTLINE_PORT", "8765")))
