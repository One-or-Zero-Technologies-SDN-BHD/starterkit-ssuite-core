<?php

namespace Ssuite\Core\Database;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

/**
 * Serialises migration runs across every app that shares one central database.
 *
 * Each S-Suite app runs `migrate --force` on deploy, and every app carries the
 * same convergent central migrations (check-then-alter). Two apps deploying at
 * the same moment can both see a column missing and both try to add it; the
 * loser fails mid-deploy. A MySQL named lock, scoped to the central database's
 * name, makes the second app wait until the first has finished.
 *
 * The lock lives on a dedicated connection, so purging or reconnecting the
 * central connection during migrations cannot strand it, and it is tracked per
 * PHP process, so a second application instance in the same process (as in a
 * test suite) re-enters it instead of waiting on itself. MySQL releases the lock
 * automatically if the migrating process dies.
 */
class CentralMigrationLock
{
    private const LOCK_CONNECTION = 'ssuite_central_migration_lock';

    private static ?string $heldName = null;

    private static int $depth = 0;

    public function __construct(private DatabaseManager $databases) {}

    public function acquire(): void
    {
        if (self::$heldName !== null) {
            self::$depth++;

            return;
        }
        if (! $this->isEnabled()) {
            return;
        }

        $connection = $this->lockConnection();
        if ($connection === null) {
            return;
        }

        $name = $this->lockName($connection);
        $timeout = max(0, (int) config('ssuite-core.central_migration_lock.timeout', 600));
        $result = $connection->selectOne('SELECT GET_LOCK(?, ?) AS acquired', [$name, $timeout]);

        if ((int) ($result->acquired ?? 0) !== 1) {
            $this->databases->purge(self::LOCK_CONNECTION);

            throw new RuntimeException(sprintf(
                'Waited %d seconds for the "%s" lock: another app is still migrating the shared central database. Re-run the deploy once it has finished.',
                $timeout,
                $name,
            ));
        }

        self::$heldName = $name;
        self::$depth = 1;
    }

    public function release(): void
    {
        if (self::$heldName === null) {
            return;
        }
        if (--self::$depth > 0) {
            return;
        }

        $name = self::$heldName;
        self::$heldName = null;
        self::$depth = 0;

        try {
            $this->databases->connection(self::LOCK_CONNECTION)->selectOne('SELECT RELEASE_LOCK(?) AS released', [$name]);
        } finally {
            $this->databases->purge(self::LOCK_CONNECTION);
        }
    }

    public function isHeld(): bool
    {
        return self::$heldName !== null;
    }

    public function lockName(Connection $connection): string
    {
        return substr('ssuite-central-migrate:'.$connection->getDatabaseName(), 0, 64);
    }

    private function isEnabled(): bool
    {
        return (bool) config('ssuite-core.central_migration_lock.enabled', true);
    }

    /**
     * A private copy of the central connection, used only for the lock.
     */
    private function lockConnection(): ?Connection
    {
        $name = (string) config('ssuite-core.central_migration_lock.connection', 'central');
        $settings = config("database.connections.{$name}");

        if (! is_array($settings) || ! in_array($settings['driver'] ?? null, ['mysql', 'mariadb'], true)) {
            return null;
        }

        config(['database.connections.'.self::LOCK_CONNECTION => $settings]);
        $this->databases->purge(self::LOCK_CONNECTION);

        return $this->databases->connection(self::LOCK_CONNECTION);
    }
}
