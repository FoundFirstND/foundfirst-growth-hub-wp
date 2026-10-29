#!/usr/bin/env bash
# Builds dist/foundfirst-growth-hub.zip from the committed code (HEAD), with production
# Composer dependencies (vendor/) included. The zip's top folder must be foundfirst-growth-hub/
# or WordPress installs updates as a different plugin.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SLUG="foundfirst-growth-hub"
cd "$ROOT"

header_version="$(sed -n 's/^ \* Version: *//p' "$SLUG.php" | tr -d '[:space:]')"
constant_version="$(sed -n "s/^define( 'FFGH_VERSION', '\(.*\)' );/\1/p" "$SLUG.php")"
if [[ "$header_version" != "$constant_version" ]]; then
	echo "Version mismatch: header says $header_version, FFGH_VERSION says $constant_version." >&2
	exit 1
fi

if [[ -n "$(git status --porcelain)" ]]; then
	echo "Warning: uncommitted changes are NOT included (the zip is built from HEAD)." >&2
fi

build="$(mktemp -d)"
trap 'rm -rf "$build"' EXIT
mkdir -p "$build/$SLUG"

git archive HEAD | tar -x -C "$build/$SLUG"

(
	cd "$build/$SLUG"
	composer install --no-dev --optimize-autoloader --classmap-authoritative --no-interaction --quiet
	rm -rf tests bin local .gitignore .gitattributes phpcs.xml.dist phpunit.xml.dist composer.json composer.lock CLAUDE.md API.md
)

mkdir -p dist
rm -f "dist/$SLUG.zip"
(cd "$build" && zip -rqX "$ROOT/dist/$SLUG.zip" "$SLUG")

echo "Built dist/$SLUG.zip (version $header_version)"
