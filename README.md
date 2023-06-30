# MariaStan

MariaStan is a static analysis tool for MariaDB queries. Its primary purpose is to serve as a basis for
[PHPStan](https://phpstan.org/) extensions.

**Currently, it is in a very early experimental stage. Therefore, if you want to use it be prepared for BC breaks,
bugs, incomplete features, ...**

## Installation

Install MariaStan using `composer require --dev schlndh/maria-stan:dev-master`. Then you'll need to add the following
to your `phpstan.neon`:

```neon
includes:
    - ./vendor/schlndh/maria-stan/extension.neon


```

## Configuration

MariaStan needs access to the database schema. The easiest way to provide it is to let it connect directly to a database.
You'll need to add the following configuration to your `phpstan.neon` and set proper credentials:

```neon
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

Alternatively, it is also possible to use MariaStan without access to the database during analysis. In that case you'll
need to first dump the schema using `MariaDbFileDbReflection::dumpSchema` and save it into a file. Here is an example
script that does that:

```php
<?php

declare(strict_types=1);

use MariaStan\DbReflection\MariaDbFileDbReflection;

require_once __DIR__ . '/vendor/autoload.php';

$mysqli = new mysqli('127.0.0.1', 'root', '');
file_put_contents(__DIR__ . '/maria-stan-schema.dump', MariaDbFileDbReflection::dumpSchema($mysqli, 'database'));
```

Then add the following to your `phpstan.neon`:

```neon
parameters:
    maria-stan:
        reflection:
            file: %rootDir%/../../../maria-stan-schema.dump
services:
    mariaDbReflection: @mariaDbFileDbReflection
```

Note that the [automatic expansion of relative paths](https://phpstan.org/config-reference#expanding-paths) only works
with PHPStan's own configuration (i.e. it's a hardcoded list of config keys). So you'll have to provide an absolute path
to the dump file.

See `extension.neon` for a complete list of parameters.

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

## Similar projects

### [staabm/phpstan-dba](https://github.com/staabm/phpstan-dba)

As far as I can tell, phpstan-dba works by executing the queries to get the information about result types, errors, ...
MariaStan on the other hand analyzes the queries statically. Benefits of phpstan-dba include:

- Easy support for multiple databases. With MariaStan this is impossible (with the possible exception of MySQL).
- More complete and reliable error checking thanks to the database doing the heavy lifting. On the other hand,
 the database only returns one error at a time, whereas MariaStan may be able to discover multiple issues at once.
- Query plan analysis. This seems infeasible to do statically. On the other hand, I'm not sure how useful this is in
 practice especially if you don't want to give phpstan-dba access to production data.
- It appears to be easier to get started with it, as it has extensions for multiple database abstractions.

There are some minor downsides to phpstan-dba's approach:

- There is no path to full static analysis (i.e. not requiring a running database at any point). MariaStan currently
 also requires a running database (to get data from `information_schema`, not necessarily at analysis time). But it is
 possible to implement `CREATE TABLE` (etc.) parsing and implement a DB reflection on top of that.
- Because the queries are executed, it has to be careful with data/schema modification queries. I saw some conditions
 that restrict it to `SELECT` queries in several places, as well as the use of transactions. Therefore, I'm not sure
 how well it supports `INSERT` etc. (there are some in tests at least).
