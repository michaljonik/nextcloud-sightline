# SPDX-FileCopyrightText: 2026 Michal Jonik
# SPDX-License-Identifier: AGPL-3.0-or-later

"""Contact sheets for judging new photo-query pairs, with the known relevant photos shown.

Input: JSON files of {query: [relative paths]} that a method returned in its top 8 and that
out/judged-pool.json does not list yet (gemma2_eval.py score --write-unjudged). One sheet
per query: top row = photos already judged relevant (green), below = numbered candidates.
After judging, record the result with --record, which adds every candidate to
judged-pool.json and the relevant ones to judgements.json, for the English query and its
Polish translation.

    uv run judge_sheets.py out/a.json out/b.json
    uv run judge_sheets.py out/a.json out/b.json --record verdicts.json   # {query: [numbers]}
"""

import argparse
import json
from pathlib import Path

from PIL import Image, ImageDraw, ImageOps

import spike

OUT = spike.OUT_ROOT
BASE = OUT / "XLM-Roberta-Base-ViT-B-32__laion5b_s13b_b90k"
CELL, COLS, LABEL_H = 190, 6, 26


def queries(file: str) -> list[str]:
    return [q.strip() for q in (spike.HERE / file).read_text().splitlines() if q.strip() and not q.startswith("#")]


def main() -> None:
    p = argparse.ArgumentParser()
    p.add_argument("files", nargs="+")
    p.add_argument("--record")
    args = p.parse_args()

    cands: dict[str, list[str]] = {}
    for f in args.files:
        for q, rels in json.loads(Path(f).read_text()).items():
            cands[q] = sorted(set(cands.get(q, [])) | set(rels))
    judg = json.loads((OUT / "judgements.json").read_text())
    pool = json.loads((OUT / "judged-pool.json").read_text())
    for q in cands:
        cands[q] = [r for r in cands[q] if r not in pool.get(q, [])]
    cands = {q: v for q, v in cands.items() if v}

    if args.record:
        verdicts = json.loads(Path(args.record).read_text())
        pl = dict(zip(queries("queries.txt"), queries("queries-pl.txt")))
        added = 0
        for q, rels in cands.items():
            relevant = [rels[i] for i in verdicts.get(q, [])]
            for key in (q, pl[q]):
                pool[key] = sorted(set(pool.get(key, [])) | set(rels))
                judg[key] = sorted(set(judg.get(key, [])) | set(relevant))
            added += len(relevant)
        (OUT / "judged-pool.json").write_text(json.dumps(pool, indent=1, ensure_ascii=False))
        (OUT / "judgements.json").write_text(json.dumps(judg, indent=1, ensure_ascii=False))
        print(f"recorded {sum(map(len, cands.values()))} pairs, {added} relevant")
        return

    rows = json.loads((BASE / "index.json").read_text())["rows"]
    thumb = {str(Path(r["path"]).relative_to(Path(rows[0]["path"]).parent)): BASE / r["thumb"] for r in rows}
    sheets = OUT / "judge-sheets"
    sheets.mkdir(exist_ok=True)
    for old in sheets.glob("*.jpg"):
        old.unlink()
    for n, (q, rels) in enumerate(cands.items()):
        known = judg.get(q, [])[:COLS]
        cand_rows = (len(rels) + COLS - 1) // COLS
        sheet = Image.new("RGB", (COLS * CELL, LABEL_H * 2 + CELL * (1 + cand_rows)), "white")
        draw = ImageDraw.Draw(sheet)
        draw.text((4, 4), f"{q}   (top: already relevant)", fill="black", font_size=18)
        for i, rel in enumerate(known):
            sheet.paste(ImageOps.fit(Image.open(thumb[rel]), (CELL - 6, CELL - 6)), (i * CELL + 3, LABEL_H + 3))
            draw.rectangle((i * CELL + 1, LABEL_H + 1, i * CELL + CELL - 2, LABEL_H + CELL - 2), outline="green", width=3)
        y0 = LABEL_H * 2 + CELL
        draw.text((4, y0 - LABEL_H + 4), "candidates", fill="black", font_size=18)
        for i, rel in enumerate(rels):
            x, y = (i % COLS) * CELL, y0 + (i // COLS) * CELL
            sheet.paste(ImageOps.fit(Image.open(thumb[rel]), (CELL - 6, CELL - 6)), (x + 3, y + 3))
            draw.rectangle((x + 3, y + 3, x + 34, y + 27), fill="black")
            draw.text((x + 7, y + 4), str(i), fill="yellow", font_size=20)
        sheet.save(sheets / f"q{n:02d}.jpg", quality=85)
    (sheets / "candidates.json").write_text(json.dumps(cands, indent=1, ensure_ascii=False))
    print(f"{len(cands)} sheets in {sheets}, {sum(map(len, cands.values()))} pairs to judge")


if __name__ == "__main__":
    main()
