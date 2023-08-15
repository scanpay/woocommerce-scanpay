#!/bin/sh

DIR="$( cd "$(dirname "$0")" ; pwd -P )/"
DEST="/var/www/woocommerce.scanpay.dev/wp-content/plugins/scanpay-for-woocommerce"

echo $DIR

function dosync {
    echo 'sync ...'
    rsync --recursive --delete-after \
        --exclude=".*" \
        --exclude="*.sh" \
        --rsync-path="/usr/bin/sudo -u nobody rsync" -e ssh "$DIR" modules:"$DEST" || exit 1

    # Change API to test env
    ssh modules "sudo -u nobody sed -i 's/dashboard\.scanpay\.dk/dashboard\.scanpay\.dev/' $DEST/woocommerce-scanpay.php"
    ssh modules "sudo -u nobody sed -i 's/api\.scanpay\.dk/api\.scanpay\.dev/g' $DEST/includes/ScanpayClient.php"
}

function watch() {
    if type inotifywait &>/dev/null; then
        inotifywait -mqr -e close_write "$1"
    elif type fswatch &>/dev/null; then
        fswatch -ro "$1"
    else
        (>&2 echo 'no available program to watch ')
        exit 1
    fi
}
dosync

watch $DIR | while read f; do
    dosync
done
