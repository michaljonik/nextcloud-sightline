# Changelog

All notable changes to Sightline are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow
[Semantic Versioning](https://semver.org/).

## [Unreleased]

First release.

### Added

- Unified Search provider for photos. Results are ranked by meaning and each one passes
  Nextcloud's permission check for the searching user.
- Indexing from file and cache events, plus a daily reconciliation: a crawl finds new,
  changed and outdated photos, a sweep finds deleted, trashed and moved ones. Background
  jobs run within a time budget and resume where they stopped.
- Images come from Nextcloud's preview system; the embedding service never reads files.
  RAW files are indexed when the Camera RAW Previews app is installed.
- Admin settings page with service status and indexing progress.
- `occ` commands: `sightline:status`, `sightline:reconcile`, `sightline:index`,
  `sightline:search` and `sightline:purge`.
- `sightline-embed`, the embedding service: ONNX Runtime and sqlite-vec, a Docker image,
  models downloaded from Hugging Face. The default model is XLM-R B/32 (MIT); SigLIP 2
  B/16 (Apache-2.0) is the option for English.
