# SPDX-FileCopyrightText: 2026 Michal Jonik
# SPDX-License-Identifier: AGPL-3.0-or-later

from __future__ import annotations

import pytest
from conftest import FakeEncoder, TOKEN

from sightline_embed.app import create_app


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
    assert client.get("/health").json() == {"model": "fake-model", "dims": 8, "count": 1}


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


def test_search_validates_input(client):
    assert client.post("/search", json={"query": "", "storage_ids": [10]}).status_code == 422
    assert client.post("/search", json={"query": "cat", "storage_ids": [10], "limit": 501}).status_code == 422
