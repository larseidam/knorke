#!/bin/bash

typeset -A ttlFiles

# knorke files
ttlFiles[./knowledge/knorke.ttl]=./knowledge/knorke.nt

for i in "${!ttlFiles[@]}"
do
    rm ${ttlFiles[$i]}
    rapper -i turtle -o ntriples $i > ${ttlFiles[$i]}
done
