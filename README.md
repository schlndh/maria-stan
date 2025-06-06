# MariaStan

MariaStan is a static analysis tool for MariaDB queries. Its primary purpose is to serve as a basis for
[PHPStan](https://phpstan.org/) extensions.

**Current status** (06. 06. 2025):

MariaStan is very much incomplete. It covers probably ~90% of use-cases in a large code-base where I use it
(hundreds of tables, thousands of queries). As a result there is not much activity. But it is actively maintained in
the sense that if something breaks for me, it will probably get fixed.

If you try to use it in your project, you are likely to run into use-cases which are not implemented
(e.g. syntax/functions which my project doesn't use). If that happens, you should be prepared to fix things for yourself
(most things should be easy).

There is no backwards-compatibility promise on anything, and there are no releases - I just use master.

MariaStan is tested with MariaDB 10.11.11 and PHP 8.1-8.4.

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
        # Change these to match your database
        reflection:
            defaultDatabase: 'database'
        db:
            host: 127.0.0.1
            port: 3306
            user: 'root'
            password: ''
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
            defaultDatabase: 'database'
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

Before you start implementing your own extension to integrate MariaStan into your project, you can quickly try it out. You can start by checking out a [simple example](examples/MySQLi/README.md) which uses the MySQLi extension.  Then you can try to call queries from your codebase via MySQLi and analyze them with the MySQLi extension to make sure that MariaStan supports the features which your projects uses.

## Features

Here is a list of features that you could implement into your own PHPStan extension based on MariaStan
(most of them should be demonstrated in the MySQLi extension):

- Type inference for query results. This alone is invaluable when using PHPStan with code that works with DB data.
  - MariaStan can sometimes infer narrower types than the types returned by the database (e.g. `mysqli_result::fetch_fields`).
    This is because MariaStan can (in simple cases) understand queries like `SELECT col FROM tbl WHERE col IS NOT NULL`
    and remove the `NULL` from the result type, whereas MariaDB doesn't seem to do that.
- Basic error detection. For example:
    - Unknown tables, columns, ...
    - ambiguous column names,
    - mismatched number of placeholders,
    - missing data for mandatory columns in `INSERT`/`REPLACE`
    - ...
- Report usage of deprecated tables/columns.
- Row count range: in simple cases MariaStan can determine the number of returned rows (e.g. `SELECT COUNT(*) FROM tbl`)
  which can be used to narrow-down the return type of methods like `mysqli_result::fetch_all` (i.e. `non-empty-array`).
- Column type overrides: You can override the type of a column (e.g. because the table is "static") and even propagate it
  automatically via foreign keys.
- Custom PHPDoc types: e.g. [MySQLiTableAssocRowType](https://github.com/schlndh/maria-stan/blob/master/src/PHPStan/Type/MySQLi/MySQLiTableAssocRowType.php).
- PHPStan result cache is invalidated when DB schema changes via [ResultCacheMetaExtension](https://phpstan.org/developing-extensions/result-cache-meta-extensions).

## Limitations

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
