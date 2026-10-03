#!/bin/bash

# execute this script before every commit
# ddev exec bash preCommit.sh
# shellcheck disable=SC2034 # needed not to optimize testing speed look at https://thephp.cc/articles/pcov-or-xdebug
XDEBUG_MODE=coverage
vendor/bin/phpunit --coverage-html public/clover/ --coverage-clover  public/clover/clover.xml
vendor/bin/phpstan analyze

vendor/bin/deptrac analyse -c deptrac.yaml
vendor/bin/deptrac analyse -c deptrac.layers.yaml