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
        with self._lock, self._db:
            self._db.execute(
                f"CREATE VIRTUAL TABLE IF NOT EXISTS {self.table} USING vec0("
                f"fileid INTEGER PRIMARY KEY, storage_id INTEGER, "
                f"embedding float[{dims}] distance_metric=cosine)")

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
        with self._lock, self._db:
            return self._db.execute(f"DELETE FROM {self.table} WHERE fileid = ?", (fileid,)).rowcount > 0

    def count(self) -> int:
        with self._lock:
            return self._db.execute(f"SELECT count(*) FROM {self.table}").fetchone()[0]

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
