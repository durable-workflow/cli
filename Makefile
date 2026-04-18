.PHONY: help test smoke-server phar binary clean

help:
	@echo "Targets:"
	@echo "  test     Run PHPUnit test suite"
	@echo "  smoke-server  Run live CLI smoke test against a running server"
	@echo "  phar     Build the durable-workflow PHAR (requires system PHP >= 8.2)"
	@echo "  binary   Build a standalone native binary (PHAR + phpmicro)"
	@echo "  clean    Remove build artifacts"

test:
	composer test

smoke-server:
	DURABLE_WORKFLOW_CLI_SMOKE=1 vendor/bin/phpunit --group integration tests/Integration/ServerSmokeTest.php

phar:
	./scripts/build.sh phar

binary:
	./scripts/build.sh binary

clean:
	./scripts/build.sh clean
