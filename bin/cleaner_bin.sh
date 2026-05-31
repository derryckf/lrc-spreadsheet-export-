#!/bin/sh

sed 's/Jonathon,Hill/Jono,Hill/g' |\
sed 's/,Dee,"anne Blackwell",/,"Dee anne",Blackwell,/gi'|\
sed "s/,Damon,Sherrif,/,Damon,Sherriff,/gi"|\
sed "s/,Liz,Staak,/,Elizabeth,Staak,/gi"|\
sed "s/,Deborah,Pauna,/,Debbie,Pauna,/gi"|\
sed "s/,Poppy,Oshea,/,Poppy,O\'shea,/gi"|\
sed "s/Daniel,O’shea/Daniel,O\'shea/gi"|\
sed "s/Daniel,Oshea/Daniel,O\'shea/gi" |\
sed 's/Anne-Marie,Loader/Annie,Loader/gi'|\
sed 's/,Anne,"Marie Loader",/,Annie,Loader,/gi'|\
sed 's/Phoebe,Mills,/Phoebe,Jackson-Mills,/gi'|\
sed 's/,Jozina,Goedhart,/,Jozina,Macqueen,/gi'|\
sed 's/Melissa,Williams,/Melissa,Jessup,/gi'|\
sed 's/Narelle,Wynwood,/Narelle,Whelan,/gi'|\
sed 's/,Phil,Gregory,/,Philip,Gregory,/gi'|\
sed 's/,Collin,Smith,/,Colin,Smith,/gi'|\
sed 's/,leigh,"de jong",/,Leigh,De-Jong,/gi'|\
sed 's/,J,"j Tai",/,Jj,Tai,/gi'|\
sed 's/,Emma,"Van Duiven",/,Emma,VanDuiven,/gi'|\
sed 's/,Damon,Wish-Wilson,/,Daemon,"Whish Wilson",/gi'|\
sed 's/,Damon,"Whish Wilson",/,Daemon,"Whish Wilson",/gi'|\
sed 's/,Damon,Whish-Wilson,/,Daemon,"Whish Wilson",/gi'|\
sed 's/Danica,Holloway,/Dianca,Holloway,/gi'|\
sed 's/Todd,Patterson,/Tj,Patterson,/gi'|\
sed 's/,Dave,Wagner,/,David,Wagner,/gi'|\
sed 's/,Susan J,Kerr,/,Susan,Kerr,/gi'|\
sed 's/,Amy,Howe,/,Amy,How,/gi'|\
sed 's/,Tech,Teh,/,Teck,Teh,/gi'|\
sed 's/,"de Wit",/,DeWit,/gi'|\
sed 's/,Sam,Wierenga,/,Samantha,Wierenga,/gi'|\
sed 's/,Hammersly,/,Hammersley,/gi'|\
sed 's/,Rebecca,Snare,/,Rebecca,Zuj,/gi'|\
sed 's/,Jessica,Zache,/,Jessica,Jones,/gi'|\
sed 's/,Nick,Hay,/,Nicholas,Hay,/gi'|\
sed 's/,Neill,Daly,/,Neil,Daly,/gi'|\
sed 's/,Oliver,Manion,/,Oliver,Mannion,/gi'|\
sed 's/,Stephen,Fitzallen,/,Steve,Fitzallen,/gi'|\
sed 's/Joseph,Rogers,/Joseph,Rogers-Snr,/g'|\
sed 's/,Jackson-mills,/,Mills,/gi'|\
sed 's/,Triffitt,/,Triffett,/gi'|\
sed 's/,Rogers Snr,/,Rogers-Snr,/gi'|\
sed 's/,"Rogers Snr",/,Rogers-Snr,/gi'|\
sed 's/,Rogers Sr,/,Rogers-Snr,/gi'|\
sed 's/,"Rogers Sr",/,Rogers-Snr,/gi'|\
sed 's/,Rogers Jnr,/,Rogers-Jnr,/gi'|\
sed 's/,"Rogers Jnr",/,Rogers-Jnr,/gi'|\
sed 's/,"Rogers Jr",/,Rogers-Jnr,/gi'|\
sed 's/,Rogers Jr,/,Rogers-Jnr,/gi'|\
sed 's/,Joseph Jr,Rogers,/,Joseph,Rogers-Jnr,/gi'|\
sed 's/,Will,Downie,/,William,Downie,/gi'|\
sed 's/,Amy,Hinds,/,Amy,Lamprecht,/gi'|\
sed 's/Patrick,Mcmahon,/Pat,McMahon,/gi'|\
sed 's/,Suburb or Town,/,Suburb_or_Town,/gi'|\
sed 's/,CHIP NUMBER,/,Chip_No,/gi'|\
sed 's/,Chip No,/,Chip_No,/gi'|\
sed 's/,Full Year Race Entry,/,eventEntry,/gi'|\
sed 's/,Weekly Race Entry,/,eventEntry,/gi'|\
sed 's/,Question2,/,Course,/gi'|\
sed 's/Georgina,Sertori/Georgie,Sertori/g' 
