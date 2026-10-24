# sightline-embed

Embedding service for Sightline. Holds a CLIP-family image and text encoder and a sqlite-vec
vector store. It knows file IDs, storage IDs and vectors only: no users, no paths, no
Nextcloud credentials. The Nextcloud app pushes preview images in and checks permissions on
every search result.

Embeddings are personal data. Run the service on the internal network only.

## Run (development)

```sh
cd embed
SIGHTLINE_TOKEN=$(cat ../dev/.embed-token) \
SIGHTLINE_MODEL_DIR=/path/to/XLM-Roberta-Base-ViT-B-32__laion5b_s13b_b90k \
SIGHTLINE_DB=../dev/sightline.db \
SIGHTLINE_THREADS=2 \
uv run sightline-embed
```

The model directory is an Immich-style ONNX export (`visual/`, `textual/`, `config.json`),
for example from `huggingface.co/immich-app`.

| Variable | Default | |
|---|---|---|
| `SIGHTLINE_MODEL_DIR` | required | model export folder; its name is the model ID |
| `SIGHTLINE_TOKEN` | required | shared bearer token, also set in the Nextcloud app |
| `SIGHTLINE_DB` | `sightline.db` | sqlite-vec database, one table per model |
| `SIGHTLINE_THREADS` | `0` (all cores) | ONNX Runtime intra-op threads |
| `SIGHTLINE_HOST`, `SIGHTLINE_PORT` | `127.0.0.1`, `8765` | listen address |

## API

All requests need `Authorization: Bearer $SIGHTLINE_TOKEN`.

| Request | Body | |
|---|---|---|
| `PUT /files/{fileid}?storage_id=N` | image bytes | encode and store (replaces) |
| `PATCH /files/{fileid}` | `{"storage_id": N}` | file moved to another storage |
| `DELETE /files/{fileid}` | | |
| `POST /search` | `{"query", "storage_ids", "offset", "limit"}` | ranked `{fileid, score}` within the given storages |
| `GET /health` | | model, dimensions, vector count |
