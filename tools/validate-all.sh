#!/bin/zsh
set -euo pipefail

ROOT_DIR=$(cd "$(dirname "$0")/.." && pwd)
cd "$ROOT_DIR"

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

if command -v node >/dev/null 2>&1; then
    RESOLVED_NODE_BIN="$(command -v node)"
elif [[ -x /opt/homebrew/bin/node ]]; then
    RESOLVED_NODE_BIN="/opt/homebrew/bin/node"
else
    RESOLVED_NODE_BIN=""
fi

PLUGIN_VERSION=$("$RESOLVED_PHP_BIN" -r '$composer = json_decode(file_get_contents("composer.json"), true, 512, JSON_THROW_ON_ERROR); echo $composer["version"];')
ADMIN_ENTRY=$("$RESOLVED_PHP_BIN" -r '$manifest = json_decode(file_get_contents("src/Resources/public/administration/.vite/manifest.json"), true, 512, JSON_THROW_ON_ERROR); $file = $manifest["main.js"]["file"] ?? null; if (!is_string($file) || $file === "") { fwrite(STDERR, "Could not resolve administration entry from Vite manifest.\n"); exit(1); } echo $file;')
ARCHIVE_PATH="release/EbizChargeShopware-v${PLUGIN_VERSION}-shopware67.zip"

echo "[1/8] PHP syntax"
find src tests tools -name '*.php' -print0 | xargs -0 -n1 "$RESOLVED_PHP_BIN" -l >/dev/null

echo "[2/8] Guardrails"
"$RESOLVED_PHP_BIN" tools/guardrail-check.php

echo "[3/8] Service graph"
"$RESOLVED_PHP_BIN" tools/service-graph-check.php

echo "[4/8] Self-tests"
"$RESOLVED_PHP_BIN" tools/self-test.php

echo "[5/8] XML parse"
for file in \
    src/Resources/config/config.xml \
    src/Resources/config/services.xml \
    src/Resources/config/services/core.xml \
    src/Resources/config/services/controllers.xml \
    src/Resources/config/services/commands.xml; do
    if command -v xmllint >/dev/null 2>&1; then
        xmllint --noout "$file"
    else
        "$RESOLVED_PHP_BIN" -r '$file = $argv[1]; $dom = new DOMDocument(); if (!$dom->load($file)) { exit(1); }' "$file"
    fi
done

echo "[6/8] Administration bundle syntax"
if [[ -n "$RESOLVED_NODE_BIN" ]]; then
    "$RESOLVED_NODE_BIN" --check "src/Resources/public/administration/$ADMIN_ENTRY"
else
    echo "Node.js not available in this environment; skipped JS syntax check for the prebuilt administration bundle."
fi

echo "[7/8] Release artifact smoke"
if [[ -f "$ARCHIVE_PATH" ]]; then
    "$RESOLVED_PHP_BIN" tools/release-smoke.php "$ARCHIVE_PATH"
else
    echo "Release ZIP not found yet; run ./tools/package-release.sh to generate it."
fi

if command -v phpunit >/dev/null 2>&1; then
    phpunit -c phpunit.xml.dist
else
    echo "PHPUnit not available in this environment; executable validation used tools/self-test.php instead."
fi

echo "[8/8] Shopware extension validator"
if command -v shopware-cli >/dev/null 2>&1; then
    shopware-cli extension validate --full --check-against highest .
else
    echo "shopware-cli not available in this environment; validator rerun remains an external gate."
fi

echo "Validation completed."
