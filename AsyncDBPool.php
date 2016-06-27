<?php

/**
 * AsyncDBPool - A wrapper for mysqli async connections
 *
 * Configure your mysql connection in the newConnection function
 *
 * Start a new pool with $pool = AsyncDBPool::get();
 * You can have multiple different pools at the same time with $secondPool = AsyncDBPool::get("someIdentifier");
 *
 * Use $pool->query('SELECT "mydemo"') to send a query to the pool
 * If you need to work with the result use
 * $pool->query('SELECT "mydemo"', function($result) {var_dump($result->fetch_all());})
 *
 * The function query takes any callback and will always pass the resultset as the first parameter
 * The callback will !!NOT!! be called immediately after the query finished since php does not allow threads
 * Use the evaluateAll to let all queries finish and apply their callback
 *
 * PHP version 5
 *
 * LICENSE: This source file is licenced under GPL3
 *
 * @author     Florian Bieringer <florian.bieringer@uni-passau.de>
 * @license    https://www.gnu.org/licenses/gpl-3.0.de.html GPL3
 */
class AsyncDBPool
{
    /* How many connections should be established per pool? */
    const MAX_QUERIES = 10;

    private $connections;
    private $queries;

    /* The asyncid is required to identify a connection in the pool */
    private $asyncid = 0;
    private $queue;
    private $error;

    /* Pools */
    private static $pools;

    /**
     * #### SET YOUR CONNECTION SETTINGS THERE! ####
     *
     * @return mysqli New connection
     */
    private static function newConnection() {
        return mysqli_connect("localhost", "user", "password", "database");
    }

    /**
     * Initiate a pool of asynchronious connections
     *
     * @param string $pool Identifier of pool
     *
     * @return self
     */
    public static function get($pool = "default")
    {
        if (self::$pools[$pool] === null) {
            self::$pools[$pool] = new self;
        }
        return self::$pools[$pool];
    }

    /**
     * Send a mysql query to the pool
     *
     * @param $query The mysql query to be evaluated
     *
     * @param callable|null $callback Callback for the query. Will receive the mysqli result set as first param.
     */
    public function query($query, callable $callback = null)
    {
        // Try to get a connection. If it failed, we have reached the maximum connections in this pool. Push it to queue
        $conn = $this->getConnection();
        if ($conn) {
            if ($callback) {
                $conn->callback = $callback;
            }
            $conn->asyncid = $this->asyncid;
            $this->queries[$this->asyncid] = $conn;
            $conn->query($query, MYSQLI_ASYNC);
            $this->asyncid++;
        } else {
            $this->queue($query, $callback);
        }
    }

    /**
     * Evaluates the already finished queries in this pool and applies callback.
     *
     * @param int $timeout Timeout to wait for connections to finish.
     */
    public function evaluate($timeout = 0)
    {
        // Devide all queries into read, reject, error
        $error = $reject = $read = $this->queries;
        mysqli_poll($read, $error, $reject, $timeout);

        // For all queries that finished
        foreach ($read as $r) {

            // Fetch their resultset
            if ($r && $set = $r->reap_async_query()) {

                // Call the callback
                if ($r->callback) {
                    $func = $r->callback;
                    $func($set);
                }
            }
        }

        // Free all finished connections for reuse
        foreach (array_merge($error, $reject, $read) as $conn) {
            $this->freeConnection($conn);
        }

        // Continue with the queue
        $this->continueQueue();
    }

    /**
     * Will evaluate all queries and apply their callback.
     */
    public function evaluateAll()
    {
        while ($this->queries || $this->queue) {
            $this->evaluate();
        }
    }

    /**
     * Get array of occured mysql errors
     *
     * @return mixed Array of errors
     */
    public function getErrors()
    {
        return $this->error;
    }

    /**
     * Will return a prepared connection to execute a query.
     *
     * @return mysqli|null
     */
    private function getConnection()
    {
        if (empty($this->connections)) {
            if (count($this->queries) >= self::MAX_QUERIES) {
                return null;
            }
            return self::newConnection();
        }
        return array_pop($this->connections);
    }

    /**
     * Will free up a connection to reuse.
     *
     * @param $connection
     */
    private function freeConnection($connection)
    {
        $error = mysqli_error($connection);
        if ($error) {
            $this->error[] = $error;
        }
        unset($this->queries[$connection->asyncid]);
        $this->connections[] = $connection;
    }

    /**
     * Continue the queued mysql queries
     */
    private function continueQueue()
    {
        while ($this->queue && $conn = $this->getConnection()) {
            $query = array_pop($this->queue);
            if ($query['callback']) {
                $conn->callback = $query['callback'];
            }
            $conn->asyncid = $this->asyncid;
            $this->queries[$this->asyncid] = $conn;
            $conn->query($query['query'], MYSQLI_ASYNC);
            $this->asyncid++;
        }
    }

    /**
     * Queue up a query
     * @param $query The query statement
     * @param $callback The callback
     */
    private function queue($query, $callback)
    {
        $this->queue[] = array("query" => $query, "callback" => $callback);
    }

}