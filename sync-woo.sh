#!/bin/sh

DIR="$( cd "$(dirname "$0")" ; pwd -P )/"
DEST="/var/www/woocommerce.scanpay.dev/wp-content/plugins/scanpay-for-woocommerce"

function dosync {
    echo 'sync ...'

    # Sync files to build folder
    rsync --recursive \
        --exclude=".*" \
        --exclude="*.sh" \
        --exclude="node_modules" \
        --exclude="build" \
        --exclude="vendor" \
        --exclude="composer.lock" \
        --exclude="composer.json" \
        --exclude="package.json" \
        --exclude="package-lock.json" \
        --exclude="phpcs.xml" \
        $DIR $DIR/build || exit 1

    sed -i 's/dashboard\.scanpay\.dk/dashboard\.scanpay\.dev/' $DIR/build/woocommerce-scanpay.php
    sed -i 's/api\.scanpay\.dk/api\.scanpay\.dev/g' $DIR/build/includes/ScanpayClient.php

    rsync --recursive \
        --rsync-path="/usr/bin/sudo -u nobody rsync" -e ssh "$DIR/build/" modules:"$DEST" || exit 1
}
dosync

inotifywait -mqr -e close_write --exclude 'build/' $DIR | while read f; do
    dosync
done
