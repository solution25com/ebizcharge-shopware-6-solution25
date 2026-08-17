#!/bin/zsh
set -euo pipefail

ROOT_DIR=$(cd "$(dirname "$0")/.." && pwd)
RELEASE_DIR="$ROOT_DIR/release"
if [[ -n "${PHP_BIN:-}" && -x "${PHP_BIN}" ]]; then
    RESOLVED_PHP_BIN="${PHP_BIN}"
elif command -v php >/dev/null 2>&1; then
    RESOLVED_PHP_BIN="$(command -v php)"
elif [[ -x /opt/homebrew/bin/php ]]; then
    RESOLVED_PHP_BIN="/opt/homebrew/bin/php"
else
    echo "PHP binary not found. Set PHP_BIN or install php." >&2
    exit 1
fi

PLUGIN_VERSION=$("$RESOLVED_PHP_BIN" -r '$composer = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR); echo $composer["version"];' "$ROOT_DIR/composer.json")
ADMIN_ENTRY=$("$RESOLVED_PHP_BIN" -r '$manifest = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR); $file = $manifest["main.js"]["file"] ?? null; if (!is_string($file) || $file === "") { fwrite(STDERR, "Could not resolve administration entry from Vite manifest.\n"); exit(1); } echo $file;' "$ROOT_DIR/src/Resources/public/administration/.vite/manifest.json")
ARCHIVE_PATH="$RELEASE_DIR/EbizChargeShopware-v${PLUGIN_VERSION}-shopware67.zip"
TMP_DIR=$(mktemp -d /tmp/ebizcharge-release-XXXXXX)
STAGE_DIR="$TMP_DIR/EbizChargeShopware"

cleanup() {
    rm -rf "$TMP_DIR"
}
trap cleanup EXIT

mkdir -p "$RELEASE_DIR" "$STAGE_DIR"

for required in "$ROOT_DIR/composer.json" "$ROOT_DIR/README.md" "$ROOT_DIR/CHANGELOG.md" "$ROOT_DIR/src/Resources/config/config.xml" "$ROOT_DIR/src/Resources/config/plugin.png" "$ROOT_DIR/src/Resources/config/services.xml" "$ROOT_DIR/src/Resources/public/administration/$ADMIN_ENTRY"; do
    if [[ ! -e "$required" ]]; then
        echo "Missing required release file: $required" >&2
        exit 1
    fi
done

cp "$ROOT_DIR/composer.json" "$STAGE_DIR/"
cp "$ROOT_DIR/README.md" "$STAGE_DIR/"
cp "$ROOT_DIR/CHANGELOG.md" "$STAGE_DIR/"
cp -R "$ROOT_DIR/src" "$STAGE_DIR/"
rm -rf "$STAGE_DIR/src/Resources/app/administration"

rm -f "$ARCHIVE_PATH"

(
    cd "$TMP_DIR"
    zip -rq "$ARCHIVE_PATH" "EbizChargeShopware" \
        -x "*/.DS_Store" \
        -x "*/__MACOSX/*" \
        -x "*/docs/*" \
        -x "*/tests/*" \
        -x "*/vendor/*" \
        -x "*/node_modules/*"
)

echo "Created archive: $ARCHIVE_PATH"
unzip -l "$ARCHIVE_PATH" | sed -n '1,80p'
