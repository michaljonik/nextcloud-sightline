# SPDX-FileCopyrightText: 2026 Michal Jonik
# SPDX-License-Identifier: AGPL-3.0-or-later

from __future__ import annotations

import sqlite3

import pytest
from conftest import DIMS, unit

from sightline_embed.store import VectorStore


def test_search_ranks_by_similarity_within_storages(store):
    store.upsert(1, 10, unit("cat"))
    store.upsert(2, 10, unit("dog"))
    store.upsert(3, 20, unit("cat"))  # identical vector, but in a storage not asked for

    hits = store.search(unit("cat"), [10], k=10)

    assert [f for f, _ in hits] == [1, 2]
    assert hits[0][1] > 0.999


def test_search_without_storages_returns_nothing(store):
    store.upsert(1, 10, unit("cat"))
    assert store.search(unit("cat"), [], k=10) == []


def test_upsert_replaces_vector_and_storage(store):
    store.upsert(1, 10, unit("dog"))
    store.upsert(1, 20, unit("cat"))

    assert store.count() == 1
    assert store.search(unit("cat"), [10], k=10) == []
    assert store.search(unit("cat"), [20], k=10)[0][0] == 1


def test_move_changes_storage_only(store):
    store.upsert(1, 10, unit("cat"))

    assert store.move(1, 20)
    assert not store.move(2, 20)
    fileid, score = store.search(unit("cat"), [20], k=1)[0]
    assert fileid == 1 and score > 0.999


def test_delete_removes_from_every_model_table(tmp_path):
    old = VectorStore(tmp_path / "v.db", "old-model", DIMS)
    new = VectorStore(tmp_path / "v.db", "new-model", DIMS)
    old.upsert(1, 10, unit("cat"))
    new.upsert(1, 10, unit("cat"))
    new.upsert(2, 10, unit("dog"))

    assert new.delete(1)
    assert not new.delete(1)
    assert old.count() == 0
    assert new.count() == 1


def test_clear_drops_every_model_table(tmp_path):
    old = VectorStore(tmp_path / "v.db", "old-model", DIMS)
    new = VectorStore(tmp_path / "v.db", "new-model", DIMS)
    old.upsert(1, 10, unit("cat"))
    new.upsert(1, 10, unit("cat"))
    new.upsert(2, 10, unit("dog"))

    assert new.clear() == 3
    assert new.count() == 0
    assert new.search(unit("cat"), [10], k=5) == []
    new.upsert(3, 10, unit("cat"))
    assert new.count() == 1


def test_model_ids_map_to_separate_tables(tmp_path):
    a = VectorStore(tmp_path / "v.db", "ViT-B-16-SigLIP2__webli", DIMS)
    b = VectorStore(tmp_path / "v.db", "XLM-Roberta-Base-ViT-B-32__laion5b_s13b_b90k", DIMS)
    a.upsert(1, 10, unit("cat"))

    assert a.table != b.table
    assert b.count() == 0


def test_models_lists_tables_with_their_export_folders(tmp_path):
    VectorStore(tmp_path / "v.db", "old-model", DIMS, tmp_path / "old").upsert(1, 10, unit("cat"))
    new = VectorStore(tmp_path / "v.db", "new-model", DIMS, tmp_path / "new")

    assert new.models() == [
        {"model": "new-model", "dims": DIMS, "model_dir": str(tmp_path / "new"), "count": 0},
        {"model": "old-model", "dims": DIMS, "model_dir": str(tmp_path / "old"), "count": 1},
    ]
    assert new.search(unit("cat"), [10], k=5, model_id="old-model")[0][0] == 1
    assert new.search(unit("cat"), [10], k=5) == []


def test_drop_removes_an_earlier_model_only(tmp_path):
    VectorStore(tmp_path / "v.db", "old-model", DIMS).upsert(1, 10, unit("cat"))
    new = VectorStore(tmp_path / "v.db", "new-model", DIMS)
    new.upsert(1, 10, unit("cat"))

    with pytest.raises(ValueError):
        new.drop("new-model")
    assert new.drop("unknown") is None
    assert new.drop("old-model") == 1
    assert [m["model"] for m in new.models()] == ["new-model"]
    assert new.count() == 1


def test_move_updates_every_model_table(tmp_path):
    VectorStore(tmp_path / "v.db", "old-model", DIMS).upsert(1, 10, unit("cat"))
    new = VectorStore(tmp_path / "v.db", "new-model", DIMS)

    assert new.move(1, 20)
    assert new.search(unit("cat"), [20], k=1, model_id="old-model")[0][0] == 1


def test_tables_from_before_the_models_table(tmp_path):
    """A database written by an earlier version has vec0 tables but no models table: the
    current model's table is registered under its model ID, any other under its table name."""
    db = tmp_path / "v.db"
    current, older = VectorStore(db, "current-model", DIMS), VectorStore(db, "older-model", DIMS)
    current.upsert(1, 10, unit("cat"))
    older.upsert(1, 10, unit("cat"))
    con = sqlite3.connect(db)
    con.execute("DROP TABLE models")
    con.commit()
    con.close()

    store = VectorStore(db, "current-model", DIMS)

    assert [(m["model"], m["count"]) for m in store.models()] == [("current-model", 1), ("vec_older_model", 1)]
    assert store.drop("vec_older_model") == 1
    assert store.count() == 1
