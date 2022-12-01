#!/bin/bash

# remove old stuff
rm -rf dist
rm -rf build

# install stuff
composer install
npm install

# build stuff
mkdir build dist
php build.php
npm run build
