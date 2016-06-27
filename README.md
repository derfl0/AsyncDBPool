# AsyncDBPool
A mysqli async wrapper

Since php does not support threads, doing a lot of queries (e.g. searching multiple tables of the database) can take up a lot of time. This will most of the time frustrate the user. Mysqli provides the solution: asynchronous queries. This way we can query the database and execute php code at the same time.
The mysqli async function are not that well documented and not that easy to use. This wrapper makes it a lot easier for you.

## Install
1. Modify the `newConnection()` function with your database connection.
(2. optional) Modify `const MAX_QUERIES = 10;` to the value of the maximum database connections per pool (Don't the wrapper automatically reuses finished connections so basically 1 would be enough here [but would ruin the async profit])

## Use
```php
<?php
include "AsyncDBPool.php";

// Create a new pool
$pool = AsyncDBPool::get();

// Measure time
$time = microtime(true);

// Send some queries which in total will sleep 6 seconds
$pool->query("SELECT 'Example 1<br>'", function($result) {$row = $result->fetch_row(); echo $row[0];});
$pool->query("SELECT SLEEP(1)");
$pool->query("SELECT 'Example 2<br>'", function($result) {$row = $result->fetch_row(); echo $row[0];});
$pool->query("SELECT SLEEP(2)");
$pool->query("SELECT 'Example 3<br>'", function($result) {$row = $result->fetch_row(); echo $row[0];});
$pool->query("SELECT SLEEP(3)");

// Evaluate all queries and show their callback 
$pool->evaluateAll();

// Show time
echo microtime(true) - $time;
```

As callback you can use any callable http://php.net/manual/de/language.types.callable.php
