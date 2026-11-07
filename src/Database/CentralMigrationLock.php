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
 * The lock is session-scoped: MySQL releases it automatically if the migrating
 * process dies, so a crashed deploy never leaves it stuck.
 */
class CentralMigrationLock
{
    private ?string $heldName = null;

    public function __construct(private DatabaseManager $databases) {}

    public function acquire(): void
    {
        if ($this->heldName !== null || ! $this->isEnabled()) {
            return;
        }

        $connection = $this->connection();
        if ($connection === null || ! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $name = $this->lockName($connection);
        $timeout = max(0, (int) config('ssuite-core.central_migration_lock.timeout', 600));
        $result = $connection->selectOne('SELECT GET_LOCK(?, ?) AS acquired', [$name, $timeout]);

        if ((int) ($result->acquired ?? 0) !== 1) {
            throw new RuntimeException(sprintf(
                'Waited %d seconds for the "%s" lock: another app is still migrating the shared central database. Re-run the deploy once it has finished.',
                $timeout,
                $name,
            ));
        }

        $this->heldName = $name;
    }

    public function release(): void
    {
        if ($this->heldName === null) {
            return;
        }

        $name = $this->heldName;
        $this->heldName = null;
        $this->connection()?->selectOne('SELECT RELEASE_LOCK(?) AS released', [$name]);
    }

    public function isHeld(): bool
    {
        return $this->heldName !== null;
    }

    public function lockName(Connection $connection): string
    {
        return substr('ssuite-central-migrate:'.$connection->getDatabaseName(), 0, 64);
    }

    private function isEnabled(): bool
    {
        return (bool) config('ssuite-core.central_migration_lock.enabled', true);
    }

    private function connection(): ?Connection
    {
        $name = (string) config('ssuite-core.central_migration_lock.connection', 'central');

        if (config("database.connections.{$name}") === null) {
            return null;
        }

        return $this->databases->connection($name);
    }
}
