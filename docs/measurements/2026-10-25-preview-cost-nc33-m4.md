# Where `IPreview` spends its time on Nextcloud 33

Date: 2026-10-25. Machine: MacBook Air M4 (dev, not the N305 target).

The walking skeleton (2026-10-24-skeleton-nc33-m4.md) showed that preview generation is
~95% of per-photo indexing time when no preview exists. Questions here: which stages of a
cold `IPreview::getPreview()` cost what, and which alternatives (Nextcloud settings,
Sightline's own decoding, a change in Nextcloud core) would make it faster?

## Setup

- `dev/compose.yaml`: `nextcloud:33-apache` (33.0.10, PHP 8.4.27, arm64 under OrbStack),
  `preview_max_x/y` 2048. GD bundled (2.1.0 compatible); Imagick with ImageMagick 7.1.1-43
  Q16, thread resource limit 1 as shipped in the image.
- Photos: 20 stock JPEGs from the `Stock` folder, 4–24 MP (median 12 MP), 7.7 MB on
  average. Lighter than the skeleton's 300-photo set (7.1 MB average, more 24 MP files), so
  absolute times are lower than there.
- Full-pipeline runs: a PHP script bootstraps Nextcloud, copies 24 photos into a new folder
  (new file IDs, so no previews exist) and calls `getPreview(1024, 1024, MODE_FILL,
  cacheResult: false)`, as `Indexer` does. 12 copies time the JPEG provider alone, the
  other 12 the full call twice (cold, then warm).
- Stage runs: a standalone PHP script repeats the GD calls that `OC\Preview\Image`,
  `OC\Image` and `Generator` make, in the same order, with JPEG quality 80 (Nextcloud's
  default). Same 20 photos.
- PIL runs: on the host (Python 3.13.17, Pillow 13.0.0), same 20 files.

## Full pipeline

| Call | n | p50 | max |
|---|---|---|---|
| JPEG provider `getThumbnail()` to max (decode, orientation, resize) | 12 | 165 ms | 311 ms |
| `getPreview()` 1024 px, cold (max preview generated and stored first) | 12 | 306 ms | 436 ms |
| `getPreview()` 1024 px, warm (max preview exists) | 12 | 38 ms | 76 ms |

## Stages of a cold preview

| Stage | `preview_max` 2048 | `preview_max` 4096 |
|---|---|---|
| Decode the full original (`imagecreatefromjpeg`) | 62–69 ms | 73 ms |
| Resize to the max size (`imagecopyresampled`) | **103 ms** | **207 ms** |
| Encode the max preview (JPEG) | 6 ms | 21 ms |
| Decode the max preview | 6 ms | 19 ms |
| Resize max → 1024 px | 17 ms | 57 ms |
| Encode the 1024 px preview | 2 ms | 2 ms |

- EXIF orientation read: 0.1 ms.
- At 4096, photos that already fit (5 of 20) skip the resize, as in Nextcloud; the
  4096 resize row covers the other 15.
- The stages add up to ~200 ms of the 306 ms cold call at 2048. The remaining ~100 ms is
  storing the max preview, its database row and framework overhead; not broken down
  further.

So a cold preview is mostly two things: GD cannot decode a JPEG at a reduced size, so the
whole original is decoded; then `imagecopyresampled` averages over every source pixel in
one thread. Each preview runs on one core, and Sightline's worker generates one preview at
a time.

## Alternatives

Resizing the full original to the 2048 box (n = 20) or the 4096 box (n = 15), decode not
included unless stated:

| Method | 2048 p50 | 4096 p50 | Note |
|---|---|---|---|
| GD `imagecopyresampled` (Nextcloud today) | 103 ms | 207 ms | |
| GD `imagescale`, `IMG_BILINEAR_FIXED` | 12 ms | 47 ms | samples 2×2 pixels, aliases on downscales above 2× |
| GD `imagescale`, `IMG_BICUBIC_FIXED` | 108 ms | 431 ms | |
| GD `imagescale`, `IMG_TRIANGLE` | 69 ms | 244 ms | |
| GD two-step: bilinear to 2× target, then `imagecopyresampled` | 96 ms | | prestep only when the photo is over 2× the box |
| Imagick decode + resize (incl. decode) | 229 ms | 386 ms | |
| Imagick with `jpeg:size` hint (libjpeg reduced-size decode, incl. decode) | 124 ms | 385 ms | GD decode + resize is 172 / 281 ms |

- Two-step resize straight from the original to a 1024 px box: 75 ms → 25 ms. It helps when
  the scale factor is above 2×; at a 2048 box most of these photos are below that.
- Two-step output was saved for a visual check but not compared; quality is unverified.
- Imagick with the hint, all the way to a JPEG for the encoder, including orientation and
  encode: 109 ms to 1024 px, 60 ms to 512 px.
- PIL, decode + orientation + resize to 448 px (enough for a 224 px encoder input): 71 ms
  from a full decode, 48 ms with `draft()` (libjpeg reduced-size decode).

## Nextcloud context

- [nextcloud/server#65149](https://github.com/nextcloud/server/pull/65149) (merged to master
  2026-10-23) decodes the max preview at a reduced size with Imagick when the requested
  preview is at most 1/8 of the max. That speeds up the 64 and 256 px previews of the Files
  views. Sightline's 1024 px request from a 2048 or 4096 max is above 1/8, so it does not
  apply.
- [nextcloud/server#62145](https://github.com/nextcloud/server/pull/62145) (July 2026,
  backported to stable22–34) disabled the Imagick preview providers as a short-term fix for
  a security issue; Imagick previews are enabled again on master and in 33.0.10. Decoding user-supplied
  originals with Imagick in core would face that history.
- Imaginary (libvips) is not measured here. Nextcloud's AIO image builds `h2non/imaginary`
  from a pinned commit; the last upstream release is v1.2.4 (2020-08), the last push
  2025-11.

## Consequences for Sightline

- Stay with `IPreview` (decision of 2026-10-24). Decoding originals in Sightline would cost
  ~50 ms per photo instead of ~300 ms, but on an instance that generates thumbnails for all
  photos anyway it adds that work rather than replacing it. It only shortens the time until
  the first search works.
- The largest lever left is parallelism: the cold path is single-threaded and CPU-bound,
  and the N305 has 8 cores. Measure 1/2/4/6 indexing workers on the N305 (single-channel
  memory and the encoder compete for the same machine).
- `preview_max_x/y` 2048 stays the documented recommendation (skeleton: ~40% faster, 3×
  less storage). Imaginary is worth one N305 run as an admin option.
- A core PR with a two-step resize in `OC\Image` is plausible but small for Sightline's
  path at a 2048 max; it needs a quality comparison first.
