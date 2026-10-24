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

import uvicorn
from fastapi import Depends, FastAPI, Header, HTTPException, Request
from pydantic import BaseModel, Field

from .encoder import Encoder
from .store import MAX_K, VectorStore

MODEL_DIR = Path(os.environ["SIGHTLINE_MODEL_DIR"])
DB_PATH = Path(os.environ.get("SIGHTLINE_DB", "sightline.db"))
TOKEN = os.environ["SIGHTLINE_TOKEN"]
THREADS = int(os.environ.get("SIGHTLINE_THREADS", "0"))

encoder = Encoder(MODEL_DIR, THREADS)
store = VectorStore(DB_PATH, encoder.model_id, encoder.dims)


def check_token(authorization: str = Header("")) -> None:
    if not hmac.compare_digest(authorization.encode(), f"Bearer {TOKEN}".encode()):
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


class Move(BaseModel):
    storage_id: int


@app.patch("/files/{fileid}")
def move_file(fileid: int, body: Move) -> dict:
    if not store.move(fileid, body.storage_id):
        raise HTTPException(404)
    return {"fileid": fileid}


@app.delete("/files/{fileid}")
def delete_file(fileid: int) -> dict:
    return {"fileid": fileid, "deleted": store.delete(fileid)}


class Search(BaseModel):
    query: str = Field(min_length=1, max_length=500)
    storage_ids: list[int] = Field(max_length=10_000)
    offset: int = Field(0, ge=0)
    limit: int = Field(20, ge=1, le=500)


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


def main() -> None:
    uvicorn.run(app, host=os.environ.get("SIGHTLINE_HOST", "127.0.0.1"), port=int(os.environ.get("SIGHTLINE_PORT", "8765")))
