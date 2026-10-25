# sightline-embed

Embedding service for Sightline. Holds a CLIP-family image and text encoder and a sqlite-vec
vector store. It knows file IDs, storage IDs and vectors only: no users, no paths, no
Nextcloud credentials. The Nextcloud app pushes preview images in and checks permissions on
every search result.

Embeddings are personal data. Run the service on the internal network only.

## Run with Docker

```sh
docker build -t sightline-embed .
docker run -d --name sightline-embed -p 8765:8765 -v sightline-embed:/data \
  -e SIGHTLINE_TOKEN=... \
  -e SIGHTLINE_MODEL=immich-app/XLM-Roberta-Base-ViT-B-32__laion5b_s13b_b90k \
  sightline-embed
```

On first start the model's ONNX files are downloaded into `/data/models`; vectors are stored
in `/data/sightline.db`. The container runs as an unprivileged user.

## Run from source (development)

```sh
cd embed
SIGHTLINE_TOKEN=$(cat ../dev/.embed-token) \
SIGHTLINE_MODEL=immich-app/XLM-Roberta-Base-ViT-B-32__laion5b_s13b_b90k \
SIGHTLINE_DB=../dev/sightline.db \
SIGHTLINE_THREADS=2 \
uv run sightline-embed
```

From the development Nextcloud container the service is reachable as
`http://host.docker.internal:8765`.

| Variable | Default | |
|---|---|---|
| `SIGHTLINE_TOKEN` | required | shared bearer token, also entered in the Nextcloud admin settings |
| `SIGHTLINE_MODEL` | | Hugging Face repo of an Immich-style export, downloaded once |
| `SIGHTLINE_MODEL_DIR` | | local export folder instead of `SIGHTLINE_MODEL`; its name is the model ID |
| `SIGHTLINE_MODELS` | `models` (`/data/models` in Docker) | where downloaded models are kept |
| `SIGHTLINE_DB` | `sightline.db` (`/data/sightline.db` in Docker) | sqlite-vec database, one table per model |
| `SIGHTLINE_THREADS` | `0` (all cores) | ONNX Runtime intra-op threads |
| `SIGHTLINE_HOST`, `SIGHTLINE_PORT` | `127.0.0.1` (`0.0.0.0` in Docker), `8765` | listen address |

A model export has `config.json`, `visual/` and `textual/` folders with ONNX files, as
published under `huggingface.co/immich-app`. Any CLIP-family model in that layout works.

## API

All requests need `Authorization: Bearer $SIGHTLINE_TOKEN`.

| Request | Body | |
|---|---|---|
| `PUT /files/{fileid}?storage_id=N` | image bytes | encode and store (replaces); 422 if the image cannot be decoded |
| `PATCH /files/{fileid}` | `{"storage_id": N}` | file moved to another storage |
| `DELETE /files/{fileid}` | | removes the file's vector from every model's table |
| `POST /search` | `{"query", "storage_ids", "offset", "limit"}` | ranked `{fileid, score}` within the given storages |
| `GET /health` | | model, dimensions, vector count |

## Tests

```sh
uv run pytest
```

The tests use a fake encoder and the real sqlite-vec store; no model download is needed.
