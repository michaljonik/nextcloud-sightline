# SPDX-FileCopyrightText: 2026 Michal Jonik
# SPDX-License-Identifier: AGPL-3.0-or-later

from __future__ import annotations

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


def test_model_ids_map_to_separate_tables(tmp_path):
    a = VectorStore(tmp_path / "v.db", "ViT-B-16-SigLIP2__webli", DIMS)
    b = VectorStore(tmp_path / "v.db", "XLM-Roberta-Base-ViT-B-32__laion5b_s13b_b90k", DIMS)
    a.upsert(1, 10, unit("cat"))

    assert a.table != b.table
    assert b.count() == 0
