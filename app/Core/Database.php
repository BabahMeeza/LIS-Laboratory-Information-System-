<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

/**
 * Pembungkus PDO tipis dengan API statis.
 *
 * Seluruh query WAJIB memakai prepared statement — tidak ada
 * penyambungan string ke dalam SQL di mana pun aplikasi ini.
 */
final class Database
{
    private static ?PDO $pdo = null;
    private static int $transactionDepth = 0;

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $host      = (string) Config::get('db.host', '127.0.0.1');
        $port      = (int) Config::get('db.port', 3306);
        $name      = (string) Config::get('db.name', 'db_lis');
        $charset   = (string) Config::get('db.charset', 'utf8mb4');
        $collation = (string) Config::get('db.collation', 'utf8mb4_unicode_ci');

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $name, $charset);

        try {
            self::$pdo = new PDO(
                $dsn,
                (string) Config::get('db.user', 'root'),
                (string) Config::get('db.pass', ''),
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::ATTR_STRINGIFY_FETCHES  => false,
                ]
            );
            self::$pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");

            // Samakan collation koneksi dengan collation tabel.
            //
            // Tanpa ini, MySQL/MariaDB memberi hasil ekspresi seperti
            // CAST(id AS CHAR) collation bawaan koneksi (umumnya
            // utf8mb4_general_ci), sementara kolom kita utf8mb4_unicode_ci.
            // Perbandingan keduanya gagal dengan galat
            // "Illegal mix of collations" — dan galat itu baru muncul saat
            // kueri tertentu dijalankan, bukan saat pemasangan.
            if ($collation !== '') {
                try {
                    self::$pdo->exec('SET NAMES ' . $charset . ' COLLATE ' . $collation);
                } catch (PDOException $e) {
                    // Collation tidak dikenal server: biarkan bawaan koneksi
                    // dan catat, jangan gagalkan seluruh aplikasi.
                    Logger::warning(
                        'Collation "' . $collation . '" tidak dikenali server, memakai bawaan koneksi. '
                        . 'Sesuaikan db.collation pada config/config.php bila terjadi galat perbandingan teks.'
                    );
                }
            }
        } catch (PDOException $e) {
            throw new RuntimeException(
                'Koneksi database gagal: ' . $e->getMessage() .
                ' — periksa config/config.php dan pastikan MySQL berjalan.',
                (int) $e->getCode()
            );
        }

        return self::$pdo;
    }

    /** @param array<int|string,mixed> $params */
    public static function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::connection()->prepare($sql);
        $stmt->execute($params);

        return $stmt;
    }

    /**
     * @param  array<int|string,mixed> $params
     * @return array<int,array<string,mixed>>
     */
    public static function select(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    /**
     * @param  array<int|string,mixed> $params
     * @return array<string,mixed>|null
     */
    public static function selectOne(string $sql, array $params = []): ?array
    {
        $row = self::query($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    /** @param array<int|string,mixed> $params */
    public static function scalar(string $sql, array $params = []): mixed
    {
        $value = self::query($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    /**
     * @param  array<int|string,mixed> $params
     * @return int Jumlah baris terpengaruh
     */
    public static function execute(string $sql, array $params = []): int
    {
        return self::query($sql, $params)->rowCount();
    }

    /** @param array<string,mixed> $data */
    public static function insert(string $table, array $data): int
    {
        $columns      = array_keys($data);
        $placeholders = array_fill(0, count($columns), '?');

        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            implode(', ', array_map(static fn ($c) => '`' . $c . '`', $columns)),
            implode(', ', $placeholders)
        );

        self::query($sql, array_values($data));

        return (int) self::connection()->lastInsertId();
    }

    /**
     * @param  array<string,mixed>     $data
     * @param  array<int|string,mixed> $whereParams
     */
    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        if ($data === []) {
            return 0;
        }

        $sets = implode(', ', array_map(static fn ($c) => '`' . $c . '` = ?', array_keys($data)));
        $sql  = sprintf('UPDATE `%s` SET %s WHERE %s', $table, $sets, $where);

        return self::execute($sql, array_merge(array_values($data), $whereParams));
    }

    /** Transaksi bersarang aman (memakai SAVEPOINT untuk level > 1). */
    public static function begin(): void
    {
        if (self::$transactionDepth === 0) {
            self::connection()->beginTransaction();
        } else {
            self::connection()->exec('SAVEPOINT lis_sp' . self::$transactionDepth);
        }
        self::$transactionDepth++;
    }

    public static function commit(): void
    {
        if (self::$transactionDepth === 0) {
            return;
        }
        self::$transactionDepth--;

        if (self::$transactionDepth === 0) {
            self::connection()->commit();
        } else {
            self::connection()->exec('RELEASE SAVEPOINT lis_sp' . self::$transactionDepth);
        }
    }

    public static function rollback(): void
    {
        if (self::$transactionDepth === 0) {
            return;
        }
        self::$transactionDepth--;

        if (self::$transactionDepth === 0) {
            if (self::connection()->inTransaction()) {
                self::connection()->rollBack();
            }
        } else {
            self::connection()->exec('ROLLBACK TO SAVEPOINT lis_sp' . self::$transactionDepth);
        }
    }

    /**
     * Jalankan callback di dalam transaksi; rollback otomatis bila melempar.
     *
     * @template T
     * @param  callable():T $callback
     * @return T
     */
    public static function transaction(callable $callback): mixed
    {
        self::begin();
        try {
            $result = $callback();
            self::commit();

            return $result;
        } catch (\Throwable $e) {
            self::rollback();
            throw $e;
        }
    }

    /**
     * Ambil nomor urut berikutnya secara atomik.
     * Dipakai untuk nomor order, nomor lab, dan barcode.
     */
    public static function nextCounter(string $nama): int
    {
        return self::transaction(static function () use ($nama): int {
            self::execute(
                'INSERT INTO counters (nama, nilai) VALUES (?, 1)
                 ON DUPLICATE KEY UPDATE nilai = nilai + 1',
                [$nama]
            );

            return (int) self::scalar('SELECT nilai FROM counters WHERE nama = ?', [$nama]);
        });
    }

    /** Cek apakah sebuah tabel ada — dipakai pengecekan instalasi. */
    public static function tableExists(string $table): bool
    {
        $count = self::scalar(
            'SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        );

        return (int) $count > 0;
    }
}
