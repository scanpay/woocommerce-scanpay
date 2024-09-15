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

if [ -d "$BUILD" ]; then
    rm -rf "${BUILD:?}/"*
    echo "Contents of $BUILD have been removed."
else
    mkdir -p "$BUILD"
fi

# Copy static files to the build directory
rsync -am --exclude='public/js' --exclude='public/css' "$SRC/" "$BUILD/"

# Convert SASS to CSS
"$DIR/node_modules/.bin/sass" --style compressed --no-source-map --verbose "$SRC/public/css/":"$BUILD/public/css/"

# Compile TypeScript to JavaScript (+minify)
for file in "$SRC/public/js/"*.ts; do
    echo "Compiling $file"
    "$DIR/node_modules/.bin/esbuild" --bundle --minify "$file" --outfile="$BUILD/public/js/$(basename "$file" .ts).js"
done

# Insert the version number into the files
for file in $(find "$BUILD" -type f \( -name "*.php" -o -name "*.js" -o -name "*.txt" \)); do
    if grep -q "{{ VERSION }}" "$file"; then
        sed -i "s/{{ VERSION }}/$VERSION/g" "$file"
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
    rsync -vrt --delete --rsync-path="/usr/bin/sudo -u nobody rsync" \
        -e ssh "$TMP/" modules:"/var/www/woocommerce.scanpay.dev/wp-content/plugins/scanpay-for-woocommerce/" || exit 1
    rm -rf "${TMP:?}"
fi
