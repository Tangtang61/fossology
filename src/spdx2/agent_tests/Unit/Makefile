# Copyright Siemens AG 2014
#
# Copying and distribution of this file, with or without modification,
# are permitted in any medium without royalty provided the copyright
# notice and this notice are preserved.  This file is offered as-is,
# without any warranty.

TOP = ../../../..
VARS = $(TOP)/Makefile.conf
include $(VARS)

LOCALAGENTDIR = ../../agent

all:

test:
	$(PHPUNIT) --bootstrap $(PHPUNIT_BOOT) *.php

coverage: test

clean:

.PHONY: all test coverage clean
