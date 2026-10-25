# sightline-embed: the embedding service for Sightline.
#
#   docker build -t sightline-embed embed/
#   docker run -d -p 127.0.0.1:8765:8765 -v sightline-embed:/data \
#     -e SIGHTLINE_TOKEN=... -e SIGHTLINE_MODEL=immich-app/XLM-Roberta-Base-ViT-B-32__laion5b_s13b_b90k \
#     sightline-embed
#
# The model is downloaded into /data/models on first start; vectors live in /data.

FROM python:3.12-slim-trixie

COPY --from=ghcr.io/astral-sh/uv:0.13.0 /uv /bin/uv
ENV UV_COMPILE_BYTECODE=1 UV_LINK_MODE=copy UV_PYTHON_DOWNLOADS=never

WORKDIR /app
COPY pyproject.toml uv.lock ./
RUN uv sync --locked --no-dev --no-install-project
COPY sightline_embed ./sightline_embed
RUN uv sync --locked --no-dev

RUN useradd --system --uid 10001 --home-dir /data sightline \
    && mkdir /data && chown sightline /data
USER sightline

ENV PATH=/app/.venv/bin:$PATH \
    HF_HOME=/data/hf \
    SIGHTLINE_HOST=0.0.0.0 \
    SIGHTLINE_DB=/data/sightline.db \
    SIGHTLINE_MODELS=/data/models
VOLUME /data
EXPOSE 8765
CMD ["sightline-embed"]
