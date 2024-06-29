This simple example showcases basic features of maria-stan via the built-in MySQLi PHPStan extension. See the example file [run.php](run.php) and PHPStan's output in [phpstan.out](phpstan.out). You can also try it out yourself:

First, clone the repository and run `composer install` in the root directory. Then set up a MariaDB instance. You can use the following docker command to run a new one (from root directory of the project):

```bash
docker run --detach --name maria-stan-example-mysqli \
--volume ./examples/MySQLi/data.sql:/docker-entrypoint-initdb.d/init.sql --env MARIADB_ROOT_PASSWORD=root --env MARIADB_DATABASE=maria-stan-example-mysqli \
--publish 13306:3306 --rm mariadb:10.11.5-jammy
```

Or you can use whatever MariaDB instance you already have running. However, you'll probably have to modify the `phpstan.neon`, because I'm using a non-standard MariaDB port to avoid conflict with other MariaDB instances already running on your system.

Finally, you can run phpstan and see the result for yourself (from root directory of the project):

`php vendor/bin/phpstan analyse -c examples/MySQLi/phpstan.neon --debug examples/MySQLi/run.php`

If you are curious about what else maria-stan can do, you can edit the `run.php` script and see what happens when you
try other queries (keep in mind that the MySQLi PHPStan extension is incomplete, so some use-cases may not be covered).
