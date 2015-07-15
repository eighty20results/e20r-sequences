#!/bin/bash
# Build script for PMPRO Sequences plugin
#
include=(classes email scheduled css images js languages pmpro-sequences.php README.txt)
short_name="pmpro-sequences"
plugin_path="${short_name}"
version=$(egrep "^Version:" ../${short_name}.php | awk '{print $2}')
metadata="../metadata.json"
src_path="../"
dst_path="../build/${plugin_path}"
kit_path="../build/kits"
kit_name="${kit_path}/${short_name}-${version}"

echo "Building kit for version ${version}"

mkdir -p ${kit_path}
mkdir -p ${dst_path}

if [[ -f  ${kit_name} ]]
then
    echo "Kit is already present. Cleaning up"
    rm -rf ${dst_path}
    rm -f ${kit_name}
fi

for p in ${include[@]}; do
	cp -R ${src_path}${p} ${dst_path}
done

cd ${dst_path}/..
zip -r ${kit_name}.zip ${plugin_path}
scp ${kit_name}.zip siteground-e20r:./www/protected-content/${short_name}/
scp ${metadata} siteground-e20r:./www/protected-content/${short_name}/
ssh siteground-e20r "ln -sf ./www/protected-content/\"${short_name}\"/\"${short_name}\"-\"${version}\".zip ./www/protected-content/\"${short_name}\".zip"
rm -rf ${dst_path}


