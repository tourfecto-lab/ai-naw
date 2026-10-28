<?php

/**
 * Tourfecto - Database Connection Class
 * اتصال PDO متقدم مع حماية ضد التوقف التام عند أخطاء الاستعلامات
 * @version 1.0.2
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class Database
{
    private $connection;
    private static $instance = null;
    private $queryLog = [];
    private $inTransaction = false;
    private $queryCount = 0;
    private $totalQueryTime = 0;

    private function __construct()
    {
        $this->connect();
    }

    private function connect(): void
    {
        try {
            $dsn = sprintf(
                "mysql:host=%s;port=%s;dbname=%s;charset=%s",
                DB_HOST,
                DB_PORT,
                DB_NAME,
                DB_CHARSET
            );

            $options = DB_OPTIONS;

            if (isset(DB_OPTIONS[PDO::MYSQL_ATTR_SSL_CA]) && DB_OPTIONS[PDO::MYSQL_ATTR_SSL_CA]) {
                $options[PDO::MYSQL_ATTR_SSL_CA] = DB_OPTIONS[PDO::MYSQL_ATTR_SSL_CA];
                $options[PDO::MYSQL_ATTR_SSL_CERT] = DB_OPTIONS[PDO::MYSQL_ATTR_SSL_CERT] ?? null;
                $options[PDO::MYSQL_ATTR_SSL_KEY] = DB_OPTIONS[PDO::MYSQL_ATTR_SSL_KEY] ?? null;
            }

            $this->connection = new PDO($dsn, DB_USER, DB_PASS, $options);

            $this->connection->exec("SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci'");
            $this->connection->exec("SET SESSION time_zone = '+00:00'");
            $this->connection->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");

        } catch (PDOException $e) {
            if (class_exists('Logger')) {
                Logger::error('Database Connection Error', [
                    'message' => $e->getMessage(),
                    'code' => $e->getCode(),
                    'trace' => $e->getTraceAsString()
                ]);
            }

            throw new Exception('Database connection failed. Please try again later.');
        }
    }

    public static function getInstance(): Database
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection(): PDO
    {
        return $this->connection;
    }

    public function query(string $sql, array $params = [], int $fetchMode = PDO::FETCH_ASSOC)
    {
        $attempt = 0;
        $maxRetries = defined('DB_MAX_RETRIES') ? DB_MAX_RETRIES : 2;
        $retryDelay = defined('DB_RETRY_DELAY') ? DB_RETRY_DELAY : 0.2;

        while (true) {
            $attempt++;
            $startTime = microtime(true);
            $this->queryCount++;

            try {
                $stmt = $this->connection->prepare($sql);

                foreach ($params as $key => $value) {
                    $paramType = $this->determineParamType($value);
                    $bindKey = is_int($key) ? $key + 1 : $key;
                    $stmt->bindValue($bindKey, $value, $paramType);
                }

                $stmt->execute();

                $executionTime = microtime(true) - $startTime;
                $this->totalQueryTime += $executionTime;
                $this->logQuery($sql, $params, $executionTime);

                $threshold = defined('DB_SLOW_QUERY_THRESHOLD') ? DB_SLOW_QUERY_THRESHOLD : 1.0;
                if ($executionTime > $threshold && class_exists('Logger')) {
                    Logger::warning('Slow query detected', [
                        'sql' => $sql,
                        'time' => $executionTime,
                        'threshold' => $threshold
                    ]);
                }

                $sqlUpper = strtoupper(trim($sql));

                if (strpos($sqlUpper, 'SELECT') === 0 || strpos($sqlUpper, 'SHOW') === 0 || strpos($sqlUpper, 'DESCRIBE') === 0) {
                    return $stmt->fetchAll($fetchMode);
                } elseif (strpos($sqlUpper, 'INSERT') === 0) {
                    return (int) $this->connection->lastInsertId();
                } elseif (strpos($sqlUpper, 'UPDATE') === 0 || strpos($sqlUpper, 'DELETE') === 0) {
                    return $stmt->rowCount();
                } elseif (strpos($sqlUpper, 'REPLACE') === 0) {
                    return (int) $this->connection->lastInsertId();
                }

                return true;

            } catch (PDOException $e) {
                $canRetry = $attempt <= $maxRetries
                    && !$this->inTransaction
                    && $this->isConnectionLostError($e);

                if ($canRetry) {
                    if (class_exists('Logger')) {
                        Logger::warning('DB connection lost mid-request, reconnecting and retrying', [
                            'attempt' => $attempt,
                            'message' => $e->getMessage()
                        ]);
                    }
                    usleep((int) ($retryDelay * 1000000));
                    if ($this->reconnect()) {
                        continue;
                    }
                }

                if (class_exists('Logger')) {
                    Logger::error('Query Error', [
                        'sql' => $sql,
                        'params' => $params,
                        'message' => $e->getMessage(),
                        'code' => $e->getCode()
                    ]);
                }

                // حماية لمنع الخطأ 500: إذا كان الاستعلام SELECT لا تسقط السيرفر وأرجع مصفوفة فارغة
                $sqlUpper = strtoupper(trim($sql));
                if (strpos($sqlUpper, 'SELECT') === 0 || strpos($sqlUpper, 'SHOW') === 0) {
                    return [];
                }

                throw new Exception('Database query failed: ' . $e->getMessage());
            }
        }
    }

    public function exec(string $sql, array $params = []): bool
    {
        $attempt = 0;
        $maxRetries = defined('DB_MAX_RETRIES') ? DB_MAX_RETRIES : 2;
        $retryDelay = defined('DB_RETRY_DELAY') ? DB_RETRY_DELAY : 0.2;

        while (true) {
            $attempt++;

            try {
                $stmt = $this->connection->prepare($sql);

                foreach ($params as $key => $value) {
                    $paramType = $this->determineParamType($value);
                    $bindKey = is_int($key) ? $key + 1 : $key;
                    $stmt->bindValue($bindKey, $value, $paramType);
                }

                return $stmt->execute();

            } catch (PDOException $e) {
                $canRetry = $attempt <= $maxRetries
                    && !$this->inTransaction
                    && $this->isConnectionLostError($e);

                if ($canRetry) {
                    if (class_exists('Logger')) {
                        Logger::warning('DB connection lost mid-request (exec), reconnecting and retrying', [
                            'attempt' => $attempt,
                            'message' => $e->getMessage()
                        ]);
                    }
                    usleep((int) ($retryDelay * 1000000));
                    if ($this->reconnect()) {
                        continue;
                    }
                }

                if (class_exists('Logger')) {
                    Logger::error('Exec Error', [
                        'sql' => $sql,
                        'params' => $params,
                        'message' => $e->getMessage()
                    ]);
                }

                return false;
            }
        }
    }

    private function isConnectionLostError(PDOException $e): bool
    {
        $code = (int) ($e->errorInfo[1] ?? $e->getCode());
        if (in_array($code, [2006, 2013], true)) {
            return true;
        }
        $message = $e->getMessage();
        return stripos($message, 'server has gone away') !== false
            || stripos($message, 'Lost connection') !== false
            || stripos($message, 'Error while sending') !== false;
    }

    private function determineParamType($value): int
    {
        if (is_int($value)) {
            return PDO::PARAM_INT;
        } elseif (is_bool($value)) {
            return PDO::PARAM_BOOL;
        } elseif (is_null($value)) {
            return PDO::PARAM_NULL;
        }
        return PDO::PARAM_STR;
    }

    public function beginTransaction(): bool
    {
        if (!$this->inTransaction) {
            $this->inTransaction = $this->connection->beginTransaction();
        }
        return $this->inTransaction;
    }

    public function commit(): bool
    {
        if ($this->inTransaction) {
            $result = $this->connection->commit();
            $this->inTransaction = false;
            return $result;
        }
        return false;
    }

    public function rollback(): bool
    {
        if ($this->inTransaction) {
            $result = $this->connection->rollback();
            $this->inTransaction = false;
            return $result;
        }
        return false;
    }

    public function transaction(callable $callback)
    {
        $nested = $this->inTransaction;
        static $sequence = 0;
        $savepoint = 'tourfecto_sp_' . (++$sequence);
        if ($nested) {
            $this->connection->exec('SAVEPOINT ' . $savepoint);
        } else {
            $this->beginTransaction();
        }
        try {
            $result = $callback($this);
            if ($nested) {
                $this->connection->exec('RELEASE SAVEPOINT ' . $savepoint);
            } else {
                $this->commit();
            }
            return $result;
        } catch (Throwable $e) {
            if ($nested) {
                $this->connection->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                $this->connection->exec('RELEASE SAVEPOINT ' . $savepoint);
            } else {
                $this->rollback();
            }
            throw $e;
        }
    }

    private function logQuery(string $sql, array $params, float $duration): void
    {
        if (!defined('DB_QUERY_LOG_ENABLED') || !DB_QUERY_LOG_ENABLED) {
            return;
        }

        $this->queryLog[] = [
            'sql' => $sql,
            'params' => $params,
            'duration' => $duration,
            'timestamp' => microtime(true)
        ];

        $maxLog = defined('DB_MAX_QUERY_LOG') ? DB_MAX_QUERY_LOG : 100;
        if (count($this->queryLog) > $maxLog) {
            array_shift($this->queryLog);
        }
    }

    public function getQueryLog(): array
    {
        return $this->queryLog;
    }

    public function getQueryStats(): array
    {
        return [
            'count' => $this->queryCount,
            'total_time' => $this->totalQueryTime,
            'avg_time' => $this->queryCount > 0 ? $this->totalQueryTime / $this->queryCount : 0,
            'log_count' => count($this->queryLog)
        ];
    }

    public function isConnected(): bool
    {
        try {
            return (bool) $this->connection->query('SELECT 1');
        } catch (PDOException $e) {
            return false;
        }
    }

    public function reconnect(): bool
    {
        try {
            $this->connection = null;
            $this->connect();
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    public function backup(?string $filename = null): bool
    {
        return false;
    }

    private function __clone() {}
    public function __wakeup() { throw new Exception('Cannot unserialize singleton'); }
    public function __destruct() { $this->connection = null; }
}