#!/bin/bash

mkdir -p var/cache
mkdir -p var/logs
mkdir -p var/weblogs
mkdir -p var/keys
mkdir -p var/clickhouse

if [[ -f var/keys/private.key && -f var/keys/public.key && -f var/keys/encryption.key ]]; then
    echo "Keys already exist. Skipping generation."
else
    echo "Create crypto keys..."
    openssl genpkey -algorithm RSA -out var/keys/private.key -pkeyopt rsa_keygen_bits:2048
    openssl rsa -pubout -in var/keys/private.key -out var/keys/public.key
    echo "Keys successfully created!"
fi

# start db migration
ddev php bin/console db:migrate 2>&1
