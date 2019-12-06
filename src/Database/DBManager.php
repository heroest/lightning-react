<?php
namespace Lightning\Database;

use Lightning\System\PendingPromises;
use Lightning\Database\{Pool, Connection, QueryResult};
use React\Promise\{PromiseInterface, Deferred};
use function Lightning\getObjectId;
use function Lightning\container;
use Lightning\Exceptions\DatabaseException;
use mysqli;

class DBManager
{
    private $pool;
    private $polling = false;
    private $working = [];
    private $linkConnection = [];

    public function __construct(Pool $pool)
    {
        $this->pool = $pool;
        $this->working = [];
    }

    public function query(string $connection_name, string $sql, string $role = 'master', string $fetch_mode = 'fetch_all')
    {
        if (!in_array($fetch_mode, Connection::FETCH_MODES, true)) {
            throw new DatabaseException("Unknown Fetch Modes: {$fetch_mode}");
        }

        if (self::isReadQuery($sql)) {
            $cache_key = PendingPromises::cacheKey($connection_name, $sql, $role, $fetch_mode);
            if ($promise = PendingPromises::get($cache_key)) {
                return $promise;
            }
        }

        $connection_promise = $this->pool->getConnection($connection_name, $role);
        $promise = $this->execute($connection_promise, $sql, $fetch_mode);

        if (isset($cache_key)) {
            PendingPromises::set($cache_key, $promise);
        }
        return $promise;
    }

    private function execute(PromiseInterface $connection_promise, string $sql, $fetch_mode): PromiseInterface
    {
        $deferred = new Deferred();
        $connection_promise->then(function(Connection $connection) use ($deferred, $sql, $fetch_mode) {
            $link = $connection->getLink();
            $link_id = getObjectId($link);
            $this->working[$link_id] = $link;
            $this->linkConnection[$link_id] = $connection;

            $promise = $connection->query($sql, $fetch_mode);
            $this->connectionPoll();
            $deferred->resolve($promise);
        });
        return $deferred->promise();
    }

    private function connectionPoll()
    {
        if ($this->polling) {
            return;
        } else {
            container()
            ->get('loop')
            ->addTimer(0, function($timer) {
                $this->polling = false;
                if (empty($this->working)) {
                    return;
                }

                $read = $error = $reject = $this->working;
                $count = mysqli_poll($read, $error, $reject, 0);
                if ((count($this->working) - intval($count)) > 0) {
                    $this->connectionPoll();
                }

                foreach ($read as $link) {
                    $link_id = getObjectId($link);
                    $connection = $this->linkConnection[$link_id];
                    unset(
                        $this->working[$link_id],
                        $this->linkConnection[$link_id]
                    );
                    if ($result = $link->reap_async_query()) {
                        $connection->resolve($result);
                    } else {
                        $connection->reject(new DatabaseException($link->error, $link->errno));
                    }
                }
            });
        }
    }

    //from Yii
    private static function isReadQuery(string $sql): bool
    {
        $pattern = '/^\s*(SELECT|SHOW|DESCRIBE)\b/i';
        return preg_match($pattern, $sql) > 0;
    }
}