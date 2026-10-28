#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Michal Jonik
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Build the app store archive build/sightline-<version>.tar.gz: one top-level sightline/
# folder with the runtime files of app/, no tests, dev dependencies or .git.
#
#   scripts/package-app.sh [outdir]

set -euo pipefail

root=$(cd "$(dirname "$0")/.." && pwd)
out=${1:-$root/build}
version=$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' "$root/app/appinfo/info.xml")

# The app store rejects a release whose version has no section in CHANGELOG.md.
if ! grep -q "^## \[$version\]" "$root/app/CHANGELOG.md"; then
	echo "app/CHANGELOG.md has no '## [$version]' section; rename [Unreleased] first" >&2
	exit 1
fi

stage=$(mktemp -d)
trap 'rm -rf "$stage"' EXIT

curl -sSfL -o "$stage/info.xsd" https://apps.nextcloud.com/schema/apps/info.xsd
xmllint --noout --schema "$stage/info.xsd" "$root/app/appinfo/info.xml"

mkdir "$stage/sightline"
for path in appinfo lib css img js templates CHANGELOG.md; do
	cp -R "$root/app/$path" "$stage/sightline/"
done
cp "$root/LICENSE" "$stage/sightline/"

mkdir -p "$out"
# COPYFILE_DISABLE keeps macOS tar from adding ._ resource-fork files.
COPYFILE_DISABLE=1 tar -czf "$out/sightline-$version.tar.gz" -C "$stage" sightline
echo "$out/sightline-$version.tar.gz"
