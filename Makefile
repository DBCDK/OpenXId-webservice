# ============================================================================
#			Literals
# ============================================================================

include literals
FORSCRED=$(subst /,\/,$(FORS_CREDENTIALS))
OXIDCRED=$(subst /,\/,$(OXID_CREDENTIALS))
OPENXID=$(subst /,\/,$(OPENXID_URL))

# ============================================================================
#			Commands
# ============================================================================

CC=php -l

# ============================================================================
#			Objects
# ============================================================================

SOURCES=$(patsubst %.php,%.chk,$(wildcard *.php))

# ============================================================================
#			Targets
# ============================================================================

all: install compile test doxygen

compile: $(SOURCES)

%.chk: %.php
	$(CC) $<

test:
	@echo "Unit tests is not implemented yet!"

install:
	cp -f openxid.ini_INSTALL openxid.ini
	sed -i 's/^;*aaa_credentials[ ]*=[ ]*.*/aaa_credentials = $(FORSCRED)/' openxid.ini
	chmod -w openxid.ini
	cp -f openxid.wsdl_INSTALL openxid.wsdl
	sed -i 's/^.*openxid.addi.dk.*/			<soap:address location="$(OPENXID)"\/>/' openxid.wsdl
	chmod -w openxid.wsdl
	cp -f robots.txt_INSTALL robots.txt
	chmod -w robots.txt

literals:
	@echo "Before installation, please copy literals_INSTALL to literals, and edit"

doxygen:
	doxygen openxid.doxygen
