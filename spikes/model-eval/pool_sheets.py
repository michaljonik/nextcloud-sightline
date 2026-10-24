"""Render out/pool.json (union of all methods' top-k per query) as numbered contact sheets.

Used to judge relevance by eye: note the numbers that match each query, then record them
in out/judgements.json as relative paths (see compare.py). Already judged photos are
marked, so a new method only adds photos that still need a look.

    uv run pool_sheets.py [--only-unjudged]
"""

import argparse
import json
from pathlib import Path

from PIL import Image, ImageDraw, ImageOps

import spike

OUT = spike.OUT_ROOT
BASE = OUT / spike.MODEL  # any indexed model; thumbnails are the same photos
CELL, COLS, LABEL_H = 130, 8, 24


def main() -> None:
    p = argparse.ArgumentParser()
    p.add_argument("--only-unjudged", action="store_true")
    args = p.parse_args()

    pool = json.loads((OUT / "pool.json").read_text())
    seen_file = OUT / "judged-pool.json"
    seen = json.loads(seen_file.read_text()) if seen_file.exists() else {}
    rows = json.loads((BASE / "index.json").read_text())["rows"]
    thumb_by_rel = {str(Path(r["path"]).relative_to(Path(rows[0]["path"]).parent)): r["thumb"] for r in rows}

    sheets = OUT / "pool-sheets"
    sheets.mkdir(exist_ok=True)
    numbering = {}
    for n, (q, rels) in enumerate(pool.items()):
        if args.only_unjudged:
            rels = [r for r in rels if r not in seen.get(q, [])]
            if not rels:
                continue
        numbering[q] = rels
        rows_needed = (len(rels) + COLS - 1) // COLS
        sheet = Image.new("RGB", (COLS * CELL, LABEL_H + rows_needed * CELL), "white")
        draw = ImageDraw.Draw(sheet)
        draw.text((4, 4), q, fill="black", font_size=16)
        for i, rel in enumerate(rels):
            x, y = (i % COLS) * CELL, LABEL_H + (i // COLS) * CELL
            sheet.paste(ImageOps.fit(Image.open(BASE / thumb_by_rel[rel]), (CELL - 4, CELL - 4)), (x + 2, y + 2))
            draw.rectangle((x + 2, y + 2, x + 30, y + 22), fill="black")
            draw.text((x + 5, y + 3), str(i), fill="yellow", font_size=16)
        sheet.save(sheets / f"q{n:02d}.jpg", quality=80)
    (OUT / "pool-numbering.json").write_text(json.dumps(numbering, indent=1))
    print(f"{len(numbering)} sheets in {sheets}, {sum(len(v) for v in numbering.values())} photos to judge")


if __name__ == "__main__":
    main()
