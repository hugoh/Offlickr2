#!/bin/sh -e

VERSION='3.1'
SHA1CHECKSUM='6ae79e26d1382137facc25d51085f1ccb3fcd3d3'
TARGET='phpFlickr'
DIR="$TARGET-$VERSION"
FILE="$DIR.tar.bz2"
URL="http://phpflickr.googlecode.com/files/$FILE"

wget -O $FILE $URL
CS=$(openssl sha1 $FILE | sed -e 's/^.* \([^ ]\)/\1/')
if [ "$SHA1CHECKSUM" = "$CS" ]; then
    tar xvjf $FILE
    if [ -e $TARGET ]; then
        rm -f $TARGET
    fi
else
    echo "Wrong checksum!"
    exit 1
fi
