#!/bin/bash

# called by Travis CI

cd ..
git clone https://github.com/EasyEngine/easyengine.git easyengine --depth=1
cd easyengine
rm -r features
cp -R ../$TEST_COMMAND/features .
rm -r vendor/easyengine/$TEST_COMMAND
cp -R ../$TEST_COMMAND vendor/easyengine/
composer update