<?php
/*
 * Thin PDO wrapper. Every query goes through prepared statements.
 */

function db()
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        cfg('db.host', 'localhost'),
        (int)cfg('db.port', 3306),
        cfg('db.name'),
        cfg('db.charset', 'utf8mb4')
    );
    try {
        $pdo = new PDO($dsn, cfg('db.user'), cfg('db.pass'), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo->exec("SET time_zone = '+00:00'");
    } catch (PDOException $e) {
        log_error('DB connection failed: ' . $e->getMessage());
        throw new ApiError('Database connection failed. Check the database settings in app/config.php.', 500, 'db_error');
    }
    return $pdo;
}

function q($sql, array $params = [])
{
    $stmt = db()->prepare($sql);
    $isList = array_keys($params) === range(0, count($params) - 1);
    $i = 1;
    foreach ($params as $key => $value) {
        if (is_int($value)) {
            $type = PDO::PARAM_INT;
        } elseif (is_bool($value)) {
            $type = PDO::PARAM_INT;
            $value = $value ? 1 : 0;
        } elseif ($value === null) {
            $type = PDO::PARAM_NULL;
        } else {
            $type = PDO::PARAM_STR;
            $value = (string)$value;
        }
        if ($isList) {
            $stmt->bindValue($i++, $value, $type);
        } else {
            $stmt->bindValue(':' . ltrim($key, ':'), $value, $type);
        }
    }
    $stmt->execute();
    return $stmt;
}

function q_all($sql, array $params = [])
{
    return q($sql, $params)->fetchAll();
}

function q_one($sql, array $params = [])
{
    $row = q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function q_val($sql, array $params = [])
{
    $value = q($sql, $params)->fetchColumn();
    return $value === false ? null : $value;
}

function q_col($sql, array $params = [])
{
    return q($sql, $params)->fetchAll(PDO::FETCH_COLUMN);
}

function q_insert($sql, array $params = [])
{
    q($sql, $params);
    return (int)db()->lastInsertId();
}

function q_exec($sql, array $params = [])
{
    return q($sql, $params)->rowCount();
}

/** Returns "?,?,?" for an IN (...) list. Always at least one placeholder. */
function placeholders(array $values)
{
    return implode(',', array_fill(0, max(1, count($values)), '?'));
}

/** Values to bind for an IN list (keeps placeholders() valid for empty lists). */
function in_values(array $values)
{
    return count($values) ? array_values($values) : [0];
}

function tx(callable $fn)
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $result = $fn();
        $pdo->commit();
        return $result;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function is_duplicate_error(Exception $e)
{
    return $e instanceof PDOException && isset($e->errorInfo[1]) && (int)$e->errorInfo[1] === 1062;
}
