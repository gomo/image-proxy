#!/bin/sh

#引数チェック
if [ -z $1 ]; then
  echo Given target directory at first arg. 1>&2
  exit 1
fi

if [ -z $2 ]; then
  echo Given image dir at second arg. 1>&2
  exit 1
fi

target_dir=$1
image_dir=$2
lib_dir=$(cd $(dirname ${0}) && pwd)

## エラーチェック
#空かどうかチェック
if [ "$(ls -A $target_dir 2> /dev/null)" != "" ]; then
  echo $target_dir is not emptry. Are you sure install here ? [y/n]
  read yes_or_no
  if [ "$yes_or_no" != "y" ]; then
    exit
  fi
fi

#存在した
if [ -e $target_dir ]; then
  #ディレクトリじゃないファイルが存在した
  if [ ! -d $target_dir ]; then
    echo $target_dir is not directory and exists. 1>&2
    exit 1
  fi
else
  #ディレクトリ作成
  echo $target_dir is not directory. Are you sure create it ? [y/n]
  read yes_or_no
  if [ "$yes_or_no" != "y" ]; then
    exit
  else
    mkdir -p $target_dir
  fi
fi

echo $target_dir
echo $lib_dir

if [ -e ${target_dir}/.htaccess ]; then
    echo ${target_dir}/.htaccess is already exists. 1>&2
    exit 1
fi

if [ -e ${target_dir}/index.php ]; then
    echo ${target_dir}/index.php is already exists. 1>&2
    exit 1
fi

if [ -e ${target_dir}/config.php ]; then
    echo ${target_dir}/config.php is already exists. 1>&2
    exit 1
fi

if [ -e ${target_dir}/$image_dir ]; then
    echo ${target_dir}/$image_dir is already exists. 1>&2
    exit 1
fi

## install

ln -s $lib_dir/web/.htaccess ${target_dir}/.htaccess
echo Create file ${target_dir}/.htaccess

ln -s $lib_dir/web/index.php ${target_dir}/index.php
echo Create file ${target_dir}/index.php


cat $lib_dir/web/config.php | sed "s/files/$image_dir/g" >  ${target_dir}/config.php
echo Create file ${target_dir}/config.php

mkdir -p ${target_dir}/${image_dir}
chmod 777 ${target_dir}/${image_dir}
echo Make directory ${target_dir}/${image_dir}




