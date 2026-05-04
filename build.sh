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

# Compile TypeScript to JavaScript (+minify)
for file in "$SRC/admin/assets/js/"*.ts; do
    echo "Compiling $file"
    "$DIR/node_modules/.bin/esbuild" --bundle --minify "$file" --outfile="$BUILD/admin/assets/js/$(basename "$file" .ts).js"
done

# Compile TypeScript to JavaScript (+minify)
for file in "$SRC/public/assets/js/"*.ts; do
    echo "Compiling $file"
    "$DIR/node_modules/.bin/esbuild" --bundle --minify "$file" --outfile="$BUILD/public/assets/js/$(basename "$file" .ts).js"
done

# Generate .mo files
"$DIR/vendor/bin/wp" i18n make-mo "$BUILD/languages"

# Generate PHP translation files
"$DIR/vendor/bin/wp" i18n make-php "$BUILD/languages"

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

read -r -p "Do you want to push to woocommerce.scanpay.dev? (y/N): " answer
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

    # Push the build to woocommerce.scanpay.dev
    rsync -rltvz --delete \
        --no-o --no-g --no-p \
        --omit-dir-times \
        -e ssh "$TMP/" \
        modules:/var/www/woocommerce.scanpay.dev/wp-content/plugins/scanpay-for-woocommerce/

    rm -rf "${TMP:?}"
fi
