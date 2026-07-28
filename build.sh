#!/bin/bash

set -e
shopt -s nullglob

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRC="$DIR/src"
BUILD="$DIR/build"
TMP="/tmp/scanpay-for-woocommerce"

# Get the verison number from package.json
VERSION=$(node -p "require('$DIR/package.json').version")
echo -e "Building version: \033[0;31m$VERSION\033[0m\n"

# Get requirements from package.json
WP_MIN=$(node -p "require('$DIR/package.json').requires.wordpress")
WP_TESTED=$(node -p "require('$DIR/package.json').tested.wordpress")
WC_MIN=$(node -p "require('$DIR/package.json').requires.woocommerce")
WC_TESTED=$(node -p "require('$DIR/package.json').tested.woocommerce")
PHP_MIN=$(node -p "require('$DIR/package.json').requires.php")

if [ -d "$BUILD" ]; then
    rm -rf "${BUILD:?}/"*
    echo "Contents of $BUILD have been removed."
else
    mkdir -p "$BUILD"
fi

# Copy static files to the build directory
rsync -am --exclude='*.css' --exclude='*.ts' "$SRC/" "$BUILD/"

# Convert SASS to CSS
"$DIR/node_modules/.bin/sass" --style compressed --no-source-map --verbose "$SRC/admin/assets/css/":"$BUILD/admin/assets/css/"
"$DIR/node_modules/.bin/sass" --style compressed --no-source-map --verbose "$SRC/public/assets/css/":"$BUILD/public/assets/css/"

# Compile TypeScript to JavaScript. Unminified for now: 'wp i18n make-pot' has no
# TypeScript parser, so the JavaScript half of the catalog can only be extracted from
# the built bundles -- and minified output loses the line references that make a POT
# reviewable. They are rebuilt minified further down, so nothing extraction-only ships.
compile_js() {
    local dir="$1"
    shift
    for file in "$SRC/$dir/assets/js/"*.ts; do
        echo "Compiling $file"
        "$DIR/node_modules/.bin/esbuild" --bundle "$@" "$file" \
            --outfile="$BUILD/$dir/assets/js/$(basename "$file" .ts).js"
    done
}
compile_js admin
compile_js public

# One i18n pipeline, run on every build, so the shipped catalogs can never predate the
# strings inside them. It extracts from $BUILD -- a plugin-shaped tree whose paths are
# the release-relative ones WordPress hashes for load_script_textdomain() -- and writes
# the authoritative POT back into the authored sources under src/languages/.
#
# Writing into src/ every build is safe only because the extraction is deterministic:
# with Report-Msgid-Bugs-To fixed and POT-Creation-Date empty, make-pot emits a
# byte-identical POT when nothing changed, and update-po then skips the write entirely.
# Drop these headers and every build dirties two tracked files. A dirty src/languages/
# after a build is therefore a signal that the strings really moved.
#
# Plural-Forms is not part of that determinism story. make-pot emits none, so a locale
# started by copying this POT compiles until its translator fills in the first plural,
# and only then fails msgfmt -- mid-work, with no hint of what is missing. Seeding the
# source language's rule costs nothing: update-po never merges headers, and msginit and
# GlotPress both override it per locale, so a locale needing another rule keeps it.
"$DIR/vendor/bin/wp" i18n make-pot "$BUILD" "$SRC/languages/scanpay-for-woocommerce.pot" \
    --headers='{"Report-Msgid-Bugs-To":"https://wordpress.org/support/plugin/scanpay-for-woocommerce","POT-Creation-Date":"","Plural-Forms":"nplurals=2; plural=(n != 1);"}'
#
# make-pot is byte-stable on its own with those headers. update-po is not: it stamps a
# fresh PO-Revision-Date on every run, even when it reports the file unchanged, so the
# previous header is put back whenever nothing else moved.
PO_BACKUP=$(mktemp -d)
for po in "$SRC/languages/"*.po; do
    cp "$po" "$PO_BACKUP/$(basename "$po")"
done
"$DIR/vendor/bin/wp" i18n update-po "$SRC/languages/scanpay-for-woocommerce.pot" "$SRC/languages"
for po in "$SRC/languages/"*.po; do
    old="$PO_BACKUP/$(basename "$po")"
    if [ -f "$old" ] && diff -q <(grep -v '^"PO-Revision-Date:' "$old") <(grep -v '^"PO-Revision-Date:' "$po") > /dev/null; then
        cp "$old" "$po"
    fi
done
rm -rf "${PO_BACKUP:?}"

rsync -am "$SRC/languages/" "$BUILD/languages/"

# Generate .mo files
"$DIR/vendor/bin/wp" i18n make-mo "$BUILD/languages"

# Generate PHP translation files
"$DIR/vendor/bin/wp" i18n make-php "$BUILD/languages"

# Generate the Jed catalogs wp_set_script_translations() loads. This wp-cli no longer
# has the --purge flag that used to strip JavaScript strings out of the source PO, so
# the shipped PO keeps them; if a future version reintroduces it, pass --no-purge.
"$DIR/vendor/bin/wp" i18n make-json "$BUILD/languages"

# Overwrite the extraction copies with the release bundles.
compile_js admin --minify
compile_js public --minify

# Last, and after both the minified bundles and the catalogs: earlier, and the POT
# header would carry a concrete version and churn on every release; later, and the
# shipped .js would keep a raw {{ VERSION }}.
for file in $(find "$BUILD" -type f \( -name "*.php" -o -name "*.js" -o -name "*.txt" \)); do
    if grep -q "{{ VERSION }}" "$file"; then
        sed -i "s/{{ VERSION }}/$VERSION/g" "$file"
    fi
    if grep -q "{{ WP_TESTED }}" "$file"; then
        sed -i "s/{{ WP_TESTED }}/$WP_TESTED/g" "$file"
    fi
    if grep -q "{{ WP_MIN }}" "$file"; then
        sed -i "s/{{ WP_MIN }}/$WP_MIN/g" "$file"
    fi
    if grep -q "{{ WC_TESTED }}" "$file"; then
        sed -i "s/{{ WC_TESTED }}/$WC_TESTED/g" "$file"
    fi
    if grep -q "{{ WC_MIN }}" "$file"; then
        sed -i "s/{{ WC_MIN }}/$WC_MIN/g" "$file"
    fi
    if grep -q "{{ PHP_MIN }}" "$file"; then
        sed -i "s/{{ PHP_MIN }}/$PHP_MIN/g" "$file"
    fi
done

read -r -p "Do you want to push to woocommerce.scanpay-modules.dev? (y/N): " answer
if [ "$answer" != "${answer#[Yy]}" ]; then
    # Copy build files to a tmp directory
    mkdir -p "$TMP"
    rsync -am --delete "$BUILD/" "$TMP/"

    # Replace production URLs with dev URLs
    for file in $(find "$TMP" -type f); do
        mtime=$(stat -c %y "$file")
        sed -i 's/dashboard\.scanpay\.dk/dashboard\.scanpay\.dev/' "$file"
        sed -i 's/betal\.scanpay\.dk/betal\.scanpay\.dev/' "$file"
        sed -i 's/api\.scanpay\.dk/api\.scanpay\.dev/g' "$file"
        touch -d "$mtime" "$file"
    done

    # Push the build to woocommerce.scanpay-modules.dev
    rsync -rltvz --delete \
        --no-o --no-g --no-p \
        --omit-dir-times \
        -e ssh "$TMP/" \
        modules:/var/www/woocommerce/wp-content/plugins/scanpay-for-woocommerce/

    rm -rf "${TMP:?}"
fi
