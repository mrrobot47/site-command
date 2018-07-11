#!/bin/bash

# called by Travis CI

# install dependencies
wget -qO ee https://rt.cx/ee4beta && sudo bash ee
rm ee
cd ..
git clone https://github.com/EasyEngine/easyengine.git easyengine --depth=1
cd easyengine
rm -r features
cp -R ../$TEST_COMMAND/features .
composer update
rm -r vendor/easyengine/$TEST_COMMAND
cp -R ../$TEST_COMMAND vendor/easyengine/
php -dphar.readonly=0 ./utils/make-phar.php easyengine.phar --quite  > /dev/null
sudo php easyengine.phar cli info