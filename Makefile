.PHONY: tests

build: cs tests phpstan

tests:
	php -d zend.assertions=1 vendor/bin/phpunit

update-tests:
	php tests/updateTests.php

cs:
	php vendor/bin/phpcs

cs-diff:
	php vendor/bin/phpcs --report=diff

cs-fix:
	php vendor/bin/phpcbf

phpstan:
	php vendor/bin/phpstan analyse
