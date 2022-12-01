#!/bin/bash
rm -rf dist
rm -rf build
mkdir build dist
php build.php
npm run build
