#!/bin/bash
#
include=(classes email scheduled css images js languages pmpro-sequences.php README.txt)
short_name="pmpro-sequences"
plugin_path="${short_name}"
version=$(egrep "^Version:" ../${short_name}.php | awk '{print $2}')
src_path="../"
dst_path="../build/${plugin_path}/"
kit_path="../build/kits"
kit_name="${kit_path}/${short_name}-${version}.zip"

if [ -f  ${kit_name} ]
then
    echo "Kit is already present. Cleaning up"
    rm -rf ${dst_path}
    rm -f ${kit_name}
fi

mkdir -p ${kit_path}
mkdir -p ${dst_path}

for p in ${include[@]}; do

	cp -R ${src_path}${p} ${dst_path}
done

cd ${dst_path}/..
zip -r ${kit_name} ${short_name}-${version}
rm -rf ${dst_path}

