<?php

declare(strict_types=1);

// @phpstan-ignore-next-line phpstanApi.phpstanNamespace
namespace PHPStan {

	use function function_exists;
	use function var_dump;

	if (! function_exists('dumpType')) {
		function dumpType(mixed $value): void
		{
			var_dump($value);
		}
	}
}

namespace {

	use function rand;

	// These credentials are not used for analysis. They are here just so you can try to run the script.
	$db = new mysqli('127.0.0.1', 'root', 'root', 'maria-stan-example-mysqli', 13306);

	// Trivial query - maria-stan should report the correct result type based on the table schema.
	$result = $db->query('SELECT * FROM schools')->fetch_all(\MYSQLI_ASSOC);
	\PHPStan\dumpType($result);

	// A slightly more complicated query. Notice that maria-stan recognizes that average_no_classes cannot be null!
	$result = $db->query('
		SELECT s.name, AVG(number_of_classes) average_no_classes
		FROM schools s
		JOIN (
		    SELECT c.school_id, cs.student_id, COUNT(*) number_of_classes
		    FROM classes c
		    JOIN class_students cs ON c.id = cs.class_id
		    GROUP BY c.school_id, cs.student_id
		) cs ON cs.school_id = s.id
		GROUP BY s.id
	')->fetch_all(\MYSQLI_ASSOC);
	\PHPStan\dumpType($result);

	// Sometimes the query can be partially dynamic. But as long as PHPStan is able to provide us with a union of
	// constant strings, such queries can still be analysed. Keep in mind that PHPStan has a limit of how many constant
	// values can be in a union, before it gets generalized. Each possible query is analysed individually,
	// and the results (i.e. return types and errors) are then combined.
	$table = rand()
		? 'schools'
		: 'people';
	$col = rand()
		? 'id'
		: '*';
	$result = $db->query("SELECT {$col} FROM {$table}" . ' WHERE 1')->fetch_all(\MYSQLI_ASSOC);
	\PHPStan\dumpType($result);

	// Here we have a trivially wrong query - it references a table which doesn't exist. Maria-stan should report it.
	try {
		$db->query('SELECT * FROM non_existent_table');
		die('This was expected to fail!');
	} catch (mysqli_sql_exception) {
	}

	// Here is a slightly more sneaky error. You can probably imagine that you have a query like this, which worked
	// when it was first written, but then someone adds a column into one of the tables which breaks the query.
	try {
		$db->query('
			SELECT * FROM schools
			UNION ALL
			SELECT * FROM people
		');
		die('This was expected to fail!');
	} catch (mysqli_sql_exception) {
	}
}
