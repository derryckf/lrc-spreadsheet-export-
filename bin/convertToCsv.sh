#!/bin/sh
#
# Converts a webscorer startlist or registation list from tab delimitered to coma delimiter 
# use cat <file>.txt | convertToCsv.sh > <outputfile>.csv
#
#simon frost 20230413
#
sed 's/,//g' | \
sed 's/\t/,/g'
