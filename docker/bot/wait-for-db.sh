#!/bin/bash

set -e
sleep 10

waitForTable() {
    table=$1;
    db=$2
    count=$(mysql -N -s -u root '-p$3' -h db -e \
        "select count(*) from information_schema.tables where \
            table_schema='${db}' and table_name='${table}';" 2>/dev/null)
    while [ "$count" -eq "0" ]; do
        sleep 1
    done
}

waitForTable $@
sleep 1

shift 3;
exec $@
