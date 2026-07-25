#!/usr/bin/env bash
#
# Sync the canonical plugin repos into Showtime's vendored module copies.
#
# The canonical source of every sub-plugin is its own repo (../craft-owl, ../craft-stub,
# ../craft-headcount) — Showtime never edits src/modules/*. This is a strictly one-way
# copy, run during development and again as the first step of a release.
#
# Usage:
#   bin/sync-modules.sh            # sync every mounted module
#   bin/sync-modules.sh stub       # sync one
#
set -euo pipefail

cd "$(dirname "$0")/.."
ROOT="$(pwd)"
SIBLINGS="$(cd .. && pwd)"

# handle:repo-directory — keep in sync with Plugin::MODULES
MODULES=(
    "stub:craft-stub"
    "headcount:craft-headcount"
    "owl:craft-owl"
)

sync_one() {
    local handle="$1" repo="$2"
    local src="$SIBLINGS/$repo/src"
    local dest="$ROOT/src/modules/$handle"

    if [ ! -d "$src" ]; then
        echo "!! $src not found — skipping $handle" >&2
        return 1
    fi

    mkdir -p "$dest"
    rsync -a --delete \
        --exclude '.DS_Store' \
        --exclude 'vendor/' \
        --exclude 'node_modules/' \
        "$src/" "$dest/"

    echo "   $handle  <-  $repo/src"
}

want="${1:-}"
echo "Syncing modules into src/modules ..."
for entry in "${MODULES[@]}"; do
    handle="${entry%%:*}"
    repo="${entry##*:}"
    if [ -z "$want" ] || [ "$want" = "$handle" ]; then
        sync_one "$handle" "$repo"
    fi
done
echo "Done."
