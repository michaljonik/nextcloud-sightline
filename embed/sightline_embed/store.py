# SPDX-FileCopyrightText: 2026 Michal Jonik
# SPDX-License-Identifier: AGPL-3.0-or-later

"""Vector store: sqlite-vec, exact (brute-force) search, one table per model.

Brute force keeps the storage_id filter exact: there is no ANN recall loss inside the set
of storages a user can see. storage_id is a metadata column, so `IN (...)` works on it.

New vectors go into the current model's table. Tables of earlier models stay until
Nextcloud drops them: during a model change the previous model keeps answering queries
until the current one has every photo (docs/architecture.md, "Changing the model"). The
`models` table remembers each model's export folder, so a restart can load the previous
model's text encoder again.
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


def table_name(model_id: str) -> str:
    return "vec_" + re.sub(r"\W", "_", model_id).lower()


class VectorStore:
    def __init__(self, path: Path, model_id: str, dims: int, model_dir: Path | None = None):
        self._db = sqlite3.connect(path, check_same_thread=False)
        self._db.enable_load_extension(True)
        sqlite_vec.load(self._db)
        self._db.enable_load_extension(False)
        self._lock = threading.Lock()
        self.model_id = model_id
        self.table = table_name(model_id)
        self._dims = dims
        with self._lock, self._db:
            self._db.execute("CREATE TABLE IF NOT EXISTS models ("
                             "model_id TEXT PRIMARY KEY, tbl TEXT NOT NULL UNIQUE, dims INTEGER NOT NULL, model_dir TEXT)")
            self._create()
            self._db.execute("INSERT INTO models(model_id, tbl, dims, model_dir) VALUES (?, ?, ?, ?) "
                             "ON CONFLICT(model_id) DO UPDATE SET model_dir = excluded.model_dir",
                             (model_id, self.table, dims, None if model_dir is None else str(model_dir)))
            self._adopt_unknown_tables()

    def _create(self) -> None:
        self._db.execute(
            f"CREATE VIRTUAL TABLE IF NOT EXISTS {self.table} USING vec0("
            f"fileid INTEGER PRIMARY KEY, storage_id INTEGER, "
            f"embedding float[{self._dims}] distance_metric=cosine)")

    def _adopt_unknown_tables(self) -> None:
        """Tables from before the models table existed are listed under their table name,
        with no export folder: they can be dropped but not searched."""
        known = {tbl for (tbl,) in self._db.execute("SELECT tbl FROM models")}
        for table, sql in self._vec_tables():
            if table not in known:
                dims = int(re.search(r"float\[(\d+)\]", sql).group(1))
                self._db.execute("INSERT OR IGNORE INTO models(model_id, tbl, dims) VALUES (?, ?, ?)",
                                 (table, table, dims))

    def upsert(self, fileid: int, storage_id: int, vector: np.ndarray) -> None:
        # vec0 has no UPSERT; delete one rowid at a time (DELETE ... IN scans the table).
        with self._lock, self._db:
            self._db.execute(f"DELETE FROM {self.table} WHERE fileid = ?", (fileid,))
            self._db.execute(f"INSERT INTO {self.table}(fileid, storage_id, embedding) VALUES (?, ?, ?)",
                             (fileid, storage_id, vector.astype(np.float32).tobytes()))

    def move(self, fileid: int, storage_id: int) -> bool:
        """Update the storage in every model's table, so the previous model's ranking still
        sees a moved photo in the right storage."""
        with self._lock, self._db:
            moved = False
            for table in self._tables():
                cur = self._db.execute(f"UPDATE {table} SET storage_id = ? WHERE fileid = ?", (storage_id, fileid))
                moved |= cur.rowcount > 0
            return moved

    def delete(self, fileid: int) -> bool:
        """Delete from every model's table: a deleted photo must not linger in an old model's
        vectors (embeddings are personal data)."""
        with self._lock, self._db:
            deleted = False
            for table in self._tables():
                deleted |= self._db.execute(f"DELETE FROM {table} WHERE fileid = ?", (fileid,)).rowcount > 0
            return deleted

    def drop(self, model_id: str) -> int | None:
        """Drop an earlier model's vectors and give the space back; None if unknown. The
        current model cannot be dropped this way."""
        if model_id == self.model_id:
            raise ValueError("the current model's vectors cannot be dropped")
        with self._lock:
            with self._db:
                row = self._db.execute("SELECT tbl FROM models WHERE model_id = ?", (model_id,)).fetchone()
                if row is None:
                    return None
                table = row[0]
                held = 0
                if table in self._tables():
                    held = self._db.execute(f"SELECT count(*) FROM {table}").fetchone()[0]
                    self._db.execute(f"DROP TABLE {table}")
                self._db.execute("DELETE FROM models WHERE model_id = ?", (model_id,))
            self._db.execute("VACUUM")
            return held

    def clear(self) -> int:
        """Drop every model's vectors and give the space back; returns how many were held."""
        with self._lock:
            with self._db:
                tables = self._tables()
                held = sum(self._db.execute(f"SELECT count(*) FROM {t}").fetchone()[0] for t in tables)
                for table in tables:
                    self._db.execute(f"DROP TABLE {table}")
                self._db.execute("DELETE FROM models WHERE model_id != ?", (self.model_id,))
                self._create()
            self._db.execute("VACUUM")
            return held

    def count(self) -> int:
        with self._lock:
            return self._db.execute(f"SELECT count(*) FROM {self.table}").fetchone()[0]

    def models(self) -> list[dict]:
        """Every model with a table: id, dims, export folder (None if unknown) and count."""
        with self._lock:
            tables = self._tables()
            rows = self._db.execute("SELECT model_id, tbl, dims, model_dir FROM models ORDER BY model_id").fetchall()
            return [{"model": model_id, "dims": dims, "model_dir": model_dir,
                     "count": self._db.execute(f"SELECT count(*) FROM {tbl}").fetchone()[0]}
                    for model_id, tbl, dims, model_dir in rows if tbl in tables]

    def _vec_tables(self) -> list[tuple[str, str]]:
        return self._db.execute("SELECT name, sql FROM sqlite_master WHERE type = 'table' "
                                "AND sql LIKE 'CREATE VIRTUAL TABLE%USING vec0(%'").fetchall()

    def _tables(self) -> list[str]:
        return [name for name, _ in self._vec_tables()]

    def search(self, vector: np.ndarray, storage_ids: list[int], k: int,
               model_id: str | None = None) -> list[tuple[int, float]]:
        """Top-k (fileid, similarity) within the given storages, best first, in the current
        model's table or in `model_id`'s."""
        if not storage_ids:
            return []
        marks = ",".join("?" * len(storage_ids))
        with self._lock:
            table = self.table
            if model_id is not None:
                row = self._db.execute("SELECT tbl FROM models WHERE model_id = ?", (model_id,)).fetchone()
                if row is None:
                    raise KeyError(model_id)
                table = row[0]
            rows = self._db.execute(
                f"SELECT fileid, distance FROM {table} "
                f"WHERE embedding MATCH ? AND k = ? AND storage_id IN ({marks}) ORDER BY distance",
                (vector.astype(np.float32).tobytes(), min(k, MAX_K), *storage_ids)).fetchall()
        return [(fileid, 1.0 - distance) for fileid, distance in rows]
