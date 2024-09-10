#!/bin/bash

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRC="$DIR/src"
BUILD="$DIR/build"
TMP="/tmp/scanpay-for-woocommerce"

# Get the verison number from package.json
VERSION=$(node -p "require('$DIR/package.json').version")
echo -e "Building version: \033[0;31m$VERSION\033[0m\n"

if [ -d "$BUILD" ]; then
    rm -rf "$BUILD"/*
    echo "Contents of $BUILD have been removed."
else
    mkdir -p "$BUILD"
fi

# Copy static files to the build directory
rsync -am --exclude='public/js' --exclude='public/css' "$SRC/" "$BUILD/"

# Build the JavaScript and CSS files
pnpm run build:js
pnpm run build:css

# Insert the version number into the files
for file in $(find "$BUILD" -type f); do
    sed -i "s/{{ VERSION }}/$VERSION/g" "$file"
done

read -p "Do you want to push to woocommerce.scanpay.dev? (y/N): " answer
if [ "$answer" != "${answer#[Yy]}" ]; then
    # Copy build files to a tmp directory
    mkdir -p "$TMP"
    rsync -am "$BUILD/" "$TMP/"

    # Replace production URLs with dev URLs
    for file in $(find "$TMP" -type f); do
        sed -i 's/dashboard\.scanpay\.dk/dashboard\.scanpay\.dev/' "$file"
        sed -i 's/betal\.scanpay\.dk/betal\.scanpay\.dev/' "$file"
        sed -i 's/api\.scanpay\.dk/api\.scanpay\.dev/g' "$file"
    done

    # Push the build to woocommerce.scanpay.dev
    rsync -vr --delete --rsync-path="/usr/bin/sudo -u nobody rsync" \
        -e ssh "$TMP/" modules:"/var/www/woocommerce.scanpay.dev/wp-content/plugins/scanpay-for-woocommerce/" || exit 1

    rm -rf "$TMP"
fi
