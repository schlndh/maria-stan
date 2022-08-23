# MariaStan

MariaStan is a static analysis tool for MariaDB queries. Its primary purpose is to serve as a basis for
[PHPStan](https://phpstan.org/) extensions.

**Currently, it is in a very early experimental stage. Therefore, if you want to use it be prepared for BC breaks,
bugs, incomplete features, ...**

## Installation

Install MariaStan using `composer require --dev schldnh/maria-stan:dev-master`. Then you'll need to add the following
to your `phpstan.neon`:

```neon
includes:
    - ./vendor/schlndh/maria-stan/extension.neon

parameters: 
    maria-stan:
        db:
            # Change these to match your database
            host: 127.0.0.1
            port: 3306
            user: 'root'
            password: ''
            database: 'db'
```

MariaStan needs access to a database to fetch the schema for query analysis. It only reads table schema and does not
write anything. Nevertheless, **DO NOT** give it access to any database which contains any important data.

## Usage

MariaStan includes a sample PHPStan extension for [MySQLi](https://www.php.net/manual/en/book.mysqli.php). However,
the purpose of this extension is simply to verify the integration with PHPStan. I do not expect anyone to actually use
MySQLi directly. Therefore, you are expected to write your own [PHPStan extension](https://phpstan.org/developing-extensions/extension-types)
that integrates with MariaStan. If you want to use the MySQLi extension include `./vendor/schlndh/maria-stan/extension.mysqli.neon`
in your `phpstan.neon`.

You can use the MySQLi extension as a starting point a modify it to match your needs. The basic idea is to get the query
string from PHPStan, pass it to MariaStan for analysis and then report result types and errors back to PHPStan.

## Limitations

- There is currently no cache-invalidation implemented for PHPStan. It is not clear if such a thing is even easily
 possible. See [this discussion](https://github.com/phpstan/phpstan/discussions/5690). However, that only matters
 for schema changes (i.e. if you change a query in a PHP file PHPStan will invalidate it automatically).
- There is currently no mechanism implemented to provide type information for methods that fetch the result as objects.
- There is no support for query builders. The analyser expects a query as a string on the input.
- The query has to be fully known statically. If you have a code like this:
    ```php
    function foo(mysqli $db, int $count) {
        return $db->prepare("SELECT * FROM tbl WHERE id IN (" . implode(',', array_fill(0, $count, '?')) . ')');
    }
    ```
  PHPStan will not be able to evaluate it statically and thus MariaStan has nothing to analyse.
- There is no support for temporary tables.
- There is no support for multiple databases.
- The limitations above are the main ones long term. But besides them, everything is only partially implemented.

