# SPDX-FileCopyrightText: 2026 Michal Jonik
# SPDX-License-Identifier: AGPL-3.0-or-later

"""Vector store: sqlite-vec, exact (brute-force) search, one table per model.

Brute force keeps the storage_id filter exact: there is no ANN recall loss inside the set
of storages a user can see. storage_id is a metadata column, so `IN (...)` works on it.
"""

from __future__ import annotations

import re
import sqlite3
import threading
from pathlib import Path

import numpy as np
import sqlite_vec

# sqlite-vec caps k per query.
MAX_K = 4096


class VectorStore:
    def __init__(self, path: Path, model_id: str, dims: int):
        self._db = sqlite3.connect(path, check_same_thread=False)
        self._db.enable_load_extension(True)
        sqlite_vec.load(self._db)
        self._db.enable_load_extension(False)
        self._lock = threading.Lock()
        self.table = "vec_" + re.sub(r"\W", "_", model_id).lower()
        self._dims = dims
        with self._lock, self._db:
            self._create()

    def _create(self) -> None:
        self._db.execute(
            f"CREATE VIRTUAL TABLE IF NOT EXISTS {self.table} USING vec0("
            f"fileid INTEGER PRIMARY KEY, storage_id INTEGER, "
            f"embedding float[{self._dims}] distance_metric=cosine)")

    def upsert(self, fileid: int, storage_id: int, vector: np.ndarray) -> None:
        # vec0 has no UPSERT; delete one rowid at a time (DELETE ... IN scans the table).
        with self._lock, self._db:
            self._db.execute(f"DELETE FROM {self.table} WHERE fileid = ?", (fileid,))
            self._db.execute(f"INSERT INTO {self.table}(fileid, storage_id, embedding) VALUES (?, ?, ?)",
                             (fileid, storage_id, vector.astype(np.float32).tobytes()))

    def move(self, fileid: int, storage_id: int) -> bool:
        with self._lock, self._db:
            cur = self._db.execute(f"UPDATE {self.table} SET storage_id = ? WHERE fileid = ?", (storage_id, fileid))
            return cur.rowcount > 0

    def delete(self, fileid: int) -> bool:
        """Delete from every model's table: a deleted photo must not linger in an old model's
        vectors (embeddings are personal data)."""
        with self._lock, self._db:
            deleted = False
            for table in self._tables():
                deleted |= self._db.execute(f"DELETE FROM {table} WHERE fileid = ?", (fileid,)).rowcount > 0
            return deleted

    def clear(self) -> int:
        """Drop every model's vectors and give the space back; returns how many were held."""
        with self._lock:
            with self._db:
                tables = self._tables()
                held = sum(self._db.execute(f"SELECT count(*) FROM {t}").fetchone()[0] for t in tables)
                for table in tables:
                    self._db.execute(f"DROP TABLE {table}")
                self._create()
            self._db.execute("VACUUM")
            return held

    def count(self) -> int:
        with self._lock:
            return self._db.execute(f"SELECT count(*) FROM {self.table}").fetchone()[0]

    def _tables(self) -> list[str]:
        rows = self._db.execute("SELECT name FROM sqlite_master WHERE type = 'table' "
                                "AND sql LIKE 'CREATE VIRTUAL TABLE%USING vec0(%'").fetchall()
        return [name for (name,) in rows]

    def search(self, vector: np.ndarray, storage_ids: list[int], k: int) -> list[tuple[int, float]]:
        """Top-k (fileid, similarity) within the given storages, best first."""
        if not storage_ids:
            return []
        marks = ",".join("?" * len(storage_ids))
        with self._lock:
            rows = self._db.execute(
                f"SELECT fileid, distance FROM {self.table} "
                f"WHERE embedding MATCH ? AND k = ? AND storage_id IN ({marks}) ORDER BY distance",
                (vector.astype(np.float32).tobytes(), min(k, MAX_K), *storage_ids)).fetchall()
        return [(fileid, 1.0 - distance) for fileid, distance in rows]
