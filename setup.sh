#!/bin/sh -e

URL='http://phpflickr.googlecode.com/files/phpflickr-3.0.tar.bz2'
FILE='phpflickr-3.0.tar.bz2'
CHECKSUM='34e6d11fec322865c0c0434d6eb28c7a4013cfde'
DIR='phpflickr-3.0'
TARGET='phpflickr'

wget -O $FILE $URL
CS=$(openssl sha1 $FILE | sed -e 's/^.* \([^ ]\)/\1/')
if [ "$CHECKSUM" = "$CS" ]; then
    tar xvjf $FILE
    if [ -e $TARGET ]; then
        rm -f $TARGET
    fi
    ln -s $DIR $TARGET
fi
