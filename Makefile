.PHONY: help test phar binary clean

help:
	@echo "Targets:"
	@echo "  test     Run PHPUnit test suite"
	@echo "  phar     Build the durable-workflow PHAR (requires system PHP >= 8.2)"
	@echo "  binary   Build a standalone native binary (PHAR + phpmicro)"
	@echo "  clean    Remove build artifacts"

test:
	composer test

phar:
	./scripts/build.sh phar

binary:
	./scripts/build.sh binary

clean:
	./scripts/build.sh clean
