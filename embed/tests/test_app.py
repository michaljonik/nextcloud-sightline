# SPDX-FileCopyrightText: 2026 Michal Jonik
# SPDX-License-Identifier: AGPL-3.0-or-later

from __future__ import annotations

import numpy as np
import pytest
from conftest import DIMS, FakeEncoder, TOKEN, unit
from fastapi.testclient import TestClient

from sightline_embed.app import create_app
from sightline_embed.store import VectorStore


def put(client, fileid, storage_id, body):
    return client.put(f"/files/{fileid}", params={"storage_id": storage_id}, content=body)


def test_requests_without_the_token_are_refused(client):
    for headers in ({"Authorization": ""}, {"Authorization": "Bearer wrong"}, {"Authorization": TOKEN}):
        assert client.get("/health", headers=headers).status_code == 401


def test_empty_token_is_not_accepted(store):
    with pytest.raises(ValueError):
        create_app(FakeEncoder(), store, "")


def test_health_reports_model_and_count(client):
    put(client, 1, 10, b"cat")
    assert client.get("/health").json() == {
        "model": "fake-model", "dims": 8, "count": 1,
        "models": [{"model": "fake-model", "dims": 8, "count": 1, "searchable": True}],
    }


def test_index_then_search(client):
    assert put(client, 1, 10, b"cat").json()["model"] == "fake-model"
    put(client, 2, 10, b"dog")
    put(client, 3, 20, b"cat")

    body = client.post("/search", json={"query": "cat", "storage_ids": [10], "limit": 5}).json()

    assert [r["fileid"] for r in body["results"]] == [1, 2]
    assert body["exhausted"] is True
    assert set(body["timing_ms"]) == {"text", "scan"}


def test_undecodable_image_is_rejected_with_422(client):
    assert put(client, 1, 10, b"broken").status_code == 422
    assert put(client, 1, 10, b"").status_code == 400
    assert client.get("/health").json()["count"] == 0


def test_pagination_by_offset(client):
    for fileid in range(1, 8):
        put(client, fileid, 10, f"photo {fileid}".encode())

    pages, offset = [], 0
    while True:
        body = client.post("/search", json={"query": "photo", "storage_ids": [10], "offset": offset, "limit": 3}).json()
        pages.append([r["fileid"] for r in body["results"]])
        offset += 3
        if body["exhausted"]:
            break

    assert [len(p) for p in pages] == [3, 3, 1]
    assert sorted(sum(pages, [])) == list(range(1, 8))


def test_move_and_delete(client):
    put(client, 1, 10, b"cat")

    assert client.patch("/files/1", json={"storage_id": 20}).status_code == 200
    assert client.patch("/files/2", json={"storage_id": 20}).status_code == 404
    assert client.post("/search", json={"query": "cat", "storage_ids": [10]}).json()["results"] == []
    assert client.delete("/files/1").json() == {"fileid": 1, "deleted": True}
    assert client.delete("/files/1").json() == {"fileid": 1, "deleted": False}


def test_delete_all(client):
    put(client, 1, 10, b"cat")
    put(client, 2, 20, b"dog")

    assert client.delete("/files").json() == {"deleted": 2}
    assert client.get("/health").json()["count"] == 0


def test_search_validates_input(client):
    assert client.post("/search", json={"query": "", "storage_ids": [10]}).status_code == 422
    assert client.post("/search", json={"query": "cat", "storage_ids": [10], "limit": 501}).status_code == 422


class PreviousEncoder:
    """Text encoder of an earlier model: its vectors differ from FakeEncoder's."""

    model_id = "old-model"
    dims = DIMS

    def encode_text(self, text: str) -> np.ndarray:
        return unit("old " + text)


@pytest.fixture
def switching(tmp_path) -> TestClient:
    """Service after a model change: old-model holds photos 1-2, fake-model only photo 3."""
    old = VectorStore(tmp_path / "v.db", "old-model", DIMS, tmp_path / "old-model")
    old.upsert(1, 10, unit("old cat"))
    old.upsert(2, 10, unit("old dog"))
    store = VectorStore(tmp_path / "v.db", FakeEncoder.model_id, DIMS)
    client = TestClient(create_app(FakeEncoder(), store, TOKEN, {"old-model": PreviousEncoder()}))
    client.headers["Authorization"] = f"Bearer {TOKEN}"
    put(client, 3, 10, b"cat")
    return client


def test_previous_model_keeps_answering_queries(switching):
    body = switching.post("/search", json={"query": "cat", "storage_ids": [10], "model": "old-model"}).json()
    assert body["model"] == "old-model"
    assert [r["fileid"] for r in body["results"]][0] == 1

    current = switching.post("/search", json={"query": "cat", "storage_ids": [10]}).json()
    assert current["model"] == "fake-model"
    assert [r["fileid"] for r in current["results"]] == [3]


def test_health_lists_every_model(switching):
    models = {m["model"]: m for m in switching.get("/health").json()["models"]}
    assert models["old-model"] == {"model": "old-model", "dims": DIMS, "count": 2, "searchable": True}
    assert models["fake-model"]["count"] == 1


def test_search_in_a_model_without_encoder_is_refused(switching):
    body = {"query": "cat", "storage_ids": [10], "model": "unknown"}
    assert switching.post("/search", json=body).status_code == 409


def test_previous_model_is_dropped_on_request(switching):
    assert switching.delete("/models/fake-model").status_code == 409
    assert switching.delete("/models/unknown").status_code == 404
    assert switching.delete("/models/old-model").json() == {"model": "old-model", "deleted": 2}

    assert [m["model"] for m in switching.get("/health").json()["models"]] == ["fake-model"]
    body = {"query": "cat", "storage_ids": [10], "model": "old-model"}
    assert switching.post("/search", json=body).status_code == 409


def test_move_and_delete_reach_the_previous_model(switching):
    switching.patch("/files/1", json={"storage_id": 20})
    body = {"query": "cat", "storage_ids": [20], "model": "old-model"}
    assert [r["fileid"] for r in switching.post("/search", json=body).json()["results"]] == [1]

    assert switching.delete("/files/2").json()["deleted"] is True
    models = {m["model"]: m["count"] for m in switching.get("/health").json()["models"]}
    assert models["old-model"] == 1
