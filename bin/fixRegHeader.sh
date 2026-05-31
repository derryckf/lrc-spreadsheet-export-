#!/bin/sh
#
# Converts a webscorer startlist or registation list from tab delimitered to coma delimiter 
# use cat <file>.txt | convertToCsv.sh > <outputfile>.csv
#
#simon frost 20230413
#
#Bib	Name	First name	Last name	Date of birth	Gender	Category	Email	Registration time	Accepted waiver	Guardian signature	Waiver signature	Total fee	Discount code	Discount	Event fee	Other items fee	Sales Tax Rate	Sales Tax	Processing Fee	Overpaid	Invoice	Extra invoices	Payment method	Would you like a T Shirt Or Singlet	Is this a Senior Registration	Fee for Is this a Senior Registration	T Shirt or Singlet	Size	What name would you like on the Top?	Do you have a timing chip?	Fee for Do you have a timing chip?	What is the number?	Phone #	Emergency contact name	Emergency contact phone #
#tagNo	Name	firstName	lastName	DOB	gender	category	email	Registration time	Accepted waiver	Guardian signature	Waiver signature	Total fee	Discount code	Discount	Event fee	Other items fee	Sales Tax Rate	Sales Tax	Processing Fee	Overpaid	Invoice	Extra invoices	Payment method	Would you like a T Shirt Or Singlet	Is this a Senior Registration	Fee for Is this a Senior Registration	T Shirt or Singlet	Size	What name would you like on the Top?	Do you have a timing chip?	Fee for Do you have a timing chip?	What is the number?	phone	Emergency contact name	Emergency contact phone #

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
sed 's/RacePass Id/racepassid/g' 

