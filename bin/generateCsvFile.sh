#!/bin/bash
#
# Converts a webscorer startlist or registation list from tab delimitered to coma delimiter
# use cat <file>.txt | convertToCsv.sh > <outputfile>.csv
#
#simon frost 20230413
#

if [ $# -ne 3 ]; then
    echo "Usage: $0 <path1> <path2> <file>"
    exit 1
fi

# Get the directory path of the script
SCRIPT_DIR=$(dirname "$0")

# Define the path to the cleaner.sh script relative to the script directory
CLEANER_SCRIPT="$SCRIPT_DIR/cleaner.sh"


path1="$1"
path2="$2"
file="$3"

if [ ! -d "$path1" ]; then
    echo "Directory '$path1' does not exist."
    exit -1
fi

if [ ! -d "$path2" ]; then
    echo "Directory '$path2' does not exist."
    exit -1
fi

if [ ! -f "$path1/$file" ]; then
    echo "File '$path1/$file' does not exist."
    exit -1
fi

output_file="$path2/$(basename "$file" .txt).csv"

cat "$path1/$file" | \
# Strip Windows \r and BOM
	sed 's/\r$//' | \
	sed '1s/^\xEF\xBB\xBF//' | \
#fixRegHeader
	sed 's/.*Bib/tagNo/g'| \
	sed 's/First name/firstName/g'| \
	sed 's/Last name/lastName/g'| \
	sed 's/Date of birth/DOB/g'| \
	sed 's/Email/email/g'| \
	sed 's/Gender/gender/g'| \
	sed 's/Distance/distance/g'| \
	sed 's/Category/category/g'| \
	sed 's/Registration time/registrationtime/g'| \
	sed 's/Phone \#/phone/g' |\
	sed 's/Predicted time/estimate/g' |\
	sed 's/Total fee/totalfee/g' |\
	sed 's/Event fee/eventfee/g' |\
	sed 's/Series Discount/seriesdiscount/g' |\
	sed 's/RacePass Discount/racepassdiscount/g' |\
	sed 's/RacePass Id/racepassid/g' |\
#convertToCsv
	sed 's/,//g' | \
	sed 's/\t/,/g' > "$output_file"

# Call the cleaner script if it exists
if [ -f "$CLEANER_SCRIPT" ]; then
    # Apply cleaner to the generated CSV in-place via temp file
    tmp_clean="${output_file}.tmp"
    cat "$output_file" | "$CLEANER_SCRIPT" > "$tmp_clean" && mv "$tmp_clean" "$output_file"
fi

exit 0
