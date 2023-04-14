.PHONY: tests

build: cs tests phpstan

tests:
	php -d zend.assertions=1 -d memory_limit=256M vendor/bin/phpunit

update-tests:
	php tests/updateTests.php

cs:
	php vendor/bin/phpcs

cs-diff:
	php vendor/bin/phpcs --report=diff

cs-fix:
	php vendor/bin/phpcbf

phpstan:
	php -d memory_limit=256M vendor/bin/phpstan analyse

phpstan-baseline:
	php -d memory_limit=256M vendor/bin/phpstan analyse --generate-baseline
