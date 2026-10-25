# SPDX-FileCopyrightText: 2026 Michal Jonik
# SPDX-License-Identifier: AGPL-3.0-or-later

from __future__ import annotations

import hashlib

import numpy as np
import pytest
from fastapi.testclient import TestClient

from sightline_embed.app import create_app
from sightline_embed.store import VectorStore

DIMS = 8
TOKEN = "test-token"


def unit(seed: str) -> np.ndarray:
    rng = np.random.default_rng(int.from_bytes(hashlib.sha256(seed.encode()).digest()[:8], "little"))
    v = rng.standard_normal(DIMS).astype(np.float32)
    return v / np.linalg.norm(v)


class FakeEncoder:
    """Images and texts map to fixed random vectors; an image body "cat" matches the query
    "cat" exactly."""

    model_id = "fake-model"
    dims = DIMS

    def encode_image(self, data: bytes) -> np.ndarray:
        if data.startswith(b"broken"):
            raise ValueError("not an image")
        return unit(data.decode())

    def encode_text(self, text: str) -> np.ndarray:
        return unit(text)


@pytest.fixture
def store(tmp_path) -> VectorStore:
    return VectorStore(tmp_path / "test.db", FakeEncoder.model_id, DIMS)


@pytest.fixture
def client(store) -> TestClient:
    client = TestClient(create_app(FakeEncoder(), store, TOKEN))
    client.headers["Authorization"] = f"Bearer {TOKEN}"
    return client
