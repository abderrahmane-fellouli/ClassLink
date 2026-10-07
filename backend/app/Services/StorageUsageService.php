<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Throwable;

/**
 * Comptabilite du volume de fichiers et garde-fou d'ecriture.
 *
 * Garde-fou sur `classlink.storage.limit_bytes` (9 Gio en
 * production) sur le bucket S3/R2, y compris lorsque plusieurs depots
 * arrivent en meme temps, **sans jamais parcourir le bucket** (une
 * reconnaissance complete coute une requete de listing par tranche de 1000
 * objets : inacceptable sur chaque upload).
 *
 * Le mecanisme tient en trois operations :
 *
 *   1. `reserve($n)`  : `UPDATE storage_usage SET reserved = reserved + n
 *                       WHERE id = 1 AND used + reserved + n <= limit`.
 *      Renvoie `false` si le plafond serait franchi. La condition est dans le
 *      `WHERE`, donc elle est evaluee **par le SGBD sous verrou** : deux
 *      transactions concurrentes ne peuvent pas toutes deux passer.
 *   2. `commit($n)`   : la reservation devient un usage reel, et l'objet est
 *      inscrit au registre.
 *   3. `release($n)`  : reservation annulee (echec du depot) ou usage rendue
 *      (suppression d'un fichier).
 *
 * Les depots S3 ne peuvent pas participer a une transaction SQL. On reserve
 * donc *avant* l'ecriture et on solde apres : tant que la reservation n'est pas
 * liberee, elle compte comme occupee. Les echecs geres liberent la reservation.
 * Un arret brutal ou une compensation impossible peut laisser un objet absent
 * du registre : voir la procedure de reprise dans docs/DEPLOYMENT.md.
 */
class StorageUsageService
{
    /**
     * Plafond effectif, en octets.
     *
     * Une configuration absente, non numerique ou negative retombe sur le
     * defaut : mieux vaut un plafond de 9 Gio qu'un garde-fou desactive par
     * une faute de frappe dans l'environnement.
     */
    public function limitBytes(): int
    {
        $configured = config('classlink.storage.limit_bytes');

        $limit = is_numeric($configured) ? (int) $configured : 0;

        return $limit > 0 ? $limit : 9663676416;
    }

    public function usedBytes(): int
    {
        return (int) $this->counter()->used_bytes;
    }

    /**
     * Espace occupe : fichiers enregistres + depots en cours.
     *
     * C'est cette valeur, et non `usedBytes()`, qui est comparee au plafond :
     * c'est elle qui empeche deux uploads paralleles de franchir la limite.
     */
    public function occupiedBytes(): int
    {
        $counter = $this->counter();

        return (int) $counter->used_bytes + (int) $counter->reserved_bytes;
    }

    public function remainingBytes(): int
    {
        return max(0, $this->limitBytes() - $this->occupiedBytes());
    }

    /**
     * Verifie qu'un fichier de `$bytes` octets tient dans le plafond.
     *
     * A appeler *avant* toute validation cote metier et avant toute ecriture
     * S3 : un refus ici ne touche ni le bucket ni la base metier, et evite de
     * faire lire un PDF de 10 Mo pour rien.
     *
     * @throws BusinessRuleException 507 des que le plafond serait franchi.
     */
    public function assertCanStore(int $bytes, string $kind = 'material'): void
    {
        $limit = $this->limitBytes();

        if ($bytes <= 0 || $bytes + $this->occupiedBytes() <= $limit) {
            return;
        }

        throw $this->capacityException($bytes, $kind);
    }

    /**
     * Bloque `$bytes` pour un depot en cours. Renvoie `false` si le plafond
     * serait franchi, sans lever : le mode "refuser silencieusement" est utile
     * quand l'appelant gere deja l'erreur.
     *
     * Cette reservation est la seule operation qui rend la limite atomique :
     * c'est elle, et non `assertCanStore()`, que les uploads concurrents se
     * disputent.
     */
    public function reserve(int $bytes): bool
    {
        if ($bytes <= 0) {
            return true;
        }

        $affected = $this->table('storage_usage')
            ->where('id', 1)
            ->whereRaw('used_bytes + reserved_bytes + ? <= ?', [$bytes, $this->limitBytes()])
            ->update([
                'reserved_bytes' => DB::raw('reserved_bytes + '.$bytes),
                'updated_at' => now(),
            ]);

        return $affected === 1;
    }

    /**
     * Solde une reservation en usage reel et inscrit l'objet au registre.
     *
     * L'insertion et la conversion se font dans la meme transaction : soit le
     * registre et le compteur convergent, soit rien n'est change.
     */
    public function commitReservation(int $reserved, int $actual, array $attributes): void
    {
        $this->write(function () use ($reserved, $actual, $attributes) {
            if ($reserved > 0) {
                $this->table('storage_usage')
                    ->where('id', 1)
                    ->update([
                        'reserved_bytes' => DB::raw('reserved_bytes - '.$reserved),
                        'updated_at' => now(),
                    ]);
            }

            $this->addUsage($actual, $attributes);
        });
    }

    /**
     * Rend `$bytes` octets : reservation annulee apres un echec d'ecriture, ou
     * usage rendu apres une suppression.
     *
     * Le `GREATEST` evite un `used_bytes` negatif si un appel est rejoue (une
     * double suppression ne doit pas rendre de la capacite fantome).
     */
    public function release(int $bytes, bool $fromReservation = true): void
    {
        if ($bytes <= 0) {
            return;
        }

        $column = $fromReservation ? 'reserved_bytes' : 'used_bytes';

        // `GREATEST` n'existe pas sous SQLite (utilise par les tests) : on
        // ecrit un `CASE` portable. Le plancher a zero evite qu'un appel
        // rejoue ne rende de la capacite fantome.
        $this->table('storage_usage')
            ->where('id', 1)
            ->update([
                $column => DB::raw('CASE WHEN '.$column.' - '.$bytes.' < 0 THEN 0 ELSE '.$column.' - '.$bytes.' END'),
                'updated_at' => now(),
            ]);
    }

    /**
     * Inscrit un objet deja ecrit dans le registre et ajoute son poids.
     *
     * Reserve a la reconciliation et aux corrections manuelles : un depot
     * normal passe par `reserve()` puis `commitReservation()`, qui est le seul
     * chemin able de garantir qu'aucun octet n'echappe au plafond.
     */
    public function addUsage(int $bytes, array $attributes): void
    {
        if ($bytes <= 0) {
            return;
        }

        $this->table('storage_usage')
            ->where('id', 1)
            ->update([
                'used_bytes' => DB::raw('used_bytes + '.$bytes),
                'updated_at' => now(),
            ]);

        $this->table('storage_objects')->insert([
            'disk' => (string) ($attributes['disk'] ?? ''),
            'path' => (string) ($attributes['path'] ?? ''),
            'size_bytes' => $bytes,
            'kind' => (string) ($attributes['kind'] ?? 'material'),
            'owner_type' => isset($attributes['owner_type']) ? (string) $attributes['owner_type'] : null,
            'owner_id' => isset($attributes['owner_id']) ? (string) $attributes['owner_id'] : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Retire un objet du registre et rend son poids.
     *
     * Le verrou pessimiste evite qu'une reconciliation concurrente ne
     * reintroduise un objet qu'on supprime en ce moment. L'absence d'`upsert`
     * rend l'appel idempotent : supprimer deux fois le meme fichier rendra 0 au
     * second appel, sans creer de capacite fantome.
     */
    public function forget(string $disk, string $path): int
    {
        return (int) $this->write(function () use ($disk, $path) {
            // Meme ordre de verrouillage que les depots et la reconciliation.
            $this->table('storage_usage')->where('id', 1)->lockForUpdate()->first();
            $row = $this->table('storage_objects')
                ->where('disk', $disk)
                ->where('path', $path)
                ->lockForUpdate()
                ->first();

            if (! $row) {
                return 0;
            }

            $this->table('storage_objects')
                ->where('disk', $disk)
                ->where('path', $path)
                ->delete();

            $bytes = (int) $row->size_bytes;

            if ($bytes > 0) {
                $this->table('storage_usage')
                    ->where('id', 1)
                    ->update([
                        'used_bytes' => DB::raw('CASE WHEN used_bytes - '.$bytes.' < 0 THEN 0 ELSE used_bytes - '.$bytes.' END'),
                        'updated_at' => now(),
                    ]);
            }

            return $bytes;
        });
    }

    /**
     * Recalcule le compteur a partir du registre.
     *
     * Repare uniquement la derive entre le compteur et le registre ; ne
     * decouvre pas les objets absents du registre.
     *
     * @return array{used:int,reserved:int,objects:int}
     */
    public function reconcile(): array
    {
        $used = (int) $this->write(function () {
            $this->table('storage_usage')->where('id', 1)->lockForUpdate()->first();
            $sum = (int) $this->table('storage_objects')->sum('size_bytes');

            $this->table('storage_usage')
                ->where('id', 1)
                ->update([
                    'used_bytes' => $sum,
                    'updated_at' => now(),
                ]);

            return $sum;
        });

        return [
            'used' => $used,
            'reserved' => $this->reservedBytes(),
            'objects' => $this->objectCount(),
        ];
    }

    public function reservedBytes(): int
    {
        return (int) $this->counter()->reserved_bytes;
    }

    /**
     * Rend toutes les reservations en attente et renvoie le nombre d'octets
     * liberes.
     *
     * Cible uniquement les reservations orphelines d'un processus disparu : un
     * arret brutal entre `reserve()` et `commitReservation()` (ou `release()`)
     * laisse des octets reserves que plus rien ne rend, puisque rien d'autre ne
     * les revendique. `reconcile()` les conserve — ils sont peut-etre le fait
     * d'un depot bien vivant.
     *
     * A n'appeler qu'application inactive, apres traitement des objets non
     * enregistres laisses par le processus disparu. Sinon la
     * commande vole la reservation d'un depot en cours, qui se retrouve ecrit
     * sans avoir reserve : deux depots simultanes franchiraient alors le
     * plafond. D'ou la confirmation explicite de
     * `classlink:storage-reconcile --release-stale-reservations`.
     */
    public function releaseAllReservations(): int
    {
        return (int) $this->write(function () {
            $counter = $this->table('storage_usage')->where('id', 1)->lockForUpdate()->first();
            $released = (int) ($counter->reserved_bytes ?? 0);

            if ($released > 0) {
                $this->table('storage_usage')->where('id', 1)->update([
                    'reserved_bytes' => 0,
                    'updated_at' => now(),
                ]);
            }

            return $released;
        });
    }

    public function objectCount(): int
    {
        return (int) $this->table('storage_objects')->count();
    }

    /**
     * Ligne unique du compteur, recreee si elle manque.
     *
     * Une base restauree sans migration, ou une ligne supprimee a la main, ne doit
     * pas provoquer une erreur SQL opaque qui remonte a l'utilisateur : on la
     * recree a la volee.
     */
    private function counter(): object
    {
        $counter = $this->table('storage_usage')->where('id', 1)->first();

        if ($counter) {
            return $counter;
        }

        try {
            $this->table('storage_usage')->insert([
                'id' => 1,
                'reserved_bytes' => 0,
                'used_bytes' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            // Course perdue : une autre requete a recree la ligne entre-temps.
        }

        return $this->table('storage_usage')->where('id', 1)->first()
            ?? (object) ['reserved_bytes' => 0, 'used_bytes' => 0];
    }

    /**
     * Annule et purge les ecritures de fichiers d'une transaction annulee.
     *
     * Une ecriture S3 ne participe pas a la transaction SQL : un `rollBack()`
     * annule la ligne `materials`/`submissions`, mais pas l'objet deja present
     * dans le seau. Sans compensation, il resterait un fichier reellement stocke
     * et invisible de la metier — et, pire, invisible de toute reconciliation
     * basee sur les tables applicatives.
     *
     * La compensation est donc la suppression de l'objet : apres un rollback,
     * le seau ne contient ni l'objet ni son poids, et l'etat est coherent dans
     * les deux sens. Elle est branchee sur l'evenement `TransactionRolledBack`,
     * donc elle ne peut pas etre oubliee par un appelant.
     *
     * Les reservations en cours ne sont pas concernees : elles sont liberees par
     * le `release()` du chemin d'ecriture, et un rollback metier les annule deja
     * puisqu'elles vivent dans la meme transaction.
     *
     * @param  Closure(): void  $purge  suppression de l'objet hors transaction
     */
    public function onRollback(Closure $purge): void
    {
        if (DB::connection()->transactionLevel() < 1) {
            // Hors transaction, rien a compenser : l'appelant valide lui-meme.
            return;
        }

        if ($this->registeredRollbackListeners === false) {
            $this->listenForRollbacks();
        }

        $this->rollbacks[] = [
            'connection' => DB::connection(),
            'level' => DB::connection()->transactionLevel(),
            'purge' => $purge,
        ];
    }

    /**
     * Un seul ecouteur, meme si plusieurs fichiers sont ecrits dans la meme
     * transaction.
     */
    private bool $registeredRollbackListeners = false;

    /**
     * @var list<array{connection: Connection, level: int, purge: Closure(): void}>
     */
    private array $rollbacks = [];

    private function listenForRollbacks(): void
    {
        $this->registeredRollbackListeners = true;

        Event::listen(TransactionRolledBack::class, function (TransactionRolledBack $event) {
            $pending = [];
            $retained = [];
            foreach ($this->rollbacks as $rollback) {
                if ($rollback['connection'] === $event->connection
                    && $rollback['level'] > $event->connection->transactionLevel()) {
                    $pending[] = $rollback['purge'];
                } else {
                    $retained[] = $rollback;
                }
            }
            $this->rollbacks = $retained;

            foreach ($pending as $purge) {
                try {
                    $purge();
                } catch (Throwable $e) {
                    /*
                     * Le disque est peut-etre hors service : on ne masque pas le
                     * rollback pour autant. L'objet non enregistre necessite
                     * une intervention cote bucket. Voir docs/DEPLOYMENT.md.
                     */
                    report($e);
                }
            }
        });

        Event::listen(TransactionCommitted::class, function (TransactionCommitted $event) {
            $retained = [];
            foreach ($this->rollbacks as $rollback) {
                if ($rollback['connection'] === $event->connection) {
                    $level = $event->connection->transactionLevel();
                    if ($level === 0) {
                        continue;
                    }
                    // Un commit de savepoint reste soumis au rollback externe.
                    $rollback['level'] = min($rollback['level'], $level);
                }
                $retained[] = $rollback;
            }
            $this->rollbacks = $retained;
        });
    }

    /**
     * Transaction de comptabilite : le registre et le compteur bougent ensemble,
     * ou pas du tout.
     */
    private function write(callable $callback): mixed
    {
        return DB::transaction($callback);
    }

    /**
     * @return Builder
     */
    private function table(string $table)
    {
        return DB::table($table);
    }

    /**
     * 507 « Insufficient Storage » : le code HTTP prevu pour un serveur dont
     * la capacite est epuisee. Un 422 serait trompeur — le fichier est valide,
     * c'est l'espace qui manque — et un 429 simulerait une limite de debit.
     */
    public function capacityException(int $bytes, string $kind = 'material'): BusinessRuleException
    {
        $limit = $this->limitBytes();

        return new BusinessRuleException(
            __('api.files.storage_capacity_reached', [
                'limit' => $this->humanBytes($limit),
            ]),
            507,
            [
                'reason' => 'storage_capacity_reached',
                'kind' => $kind,
                'limit_bytes' => $limit,
                'used_bytes' => $this->usedBytes(),
                'requested_bytes' => $bytes,
                'remaining_bytes' => max(0, $limit - $this->occupiedBytes()),
            ]
        );
    }

    /**
     * Capacite en unites lisibles pour le message (« 9 Gio »).
     */
    private function humanBytes(int $bytes): string
    {
        $units = ['o', 'Kio', 'Mio', 'Gio', 'Tio'];
        $index = 0;
        $value = (float) $bytes;

        while ($value >= 1024 && $index < count($units) - 1) {
            $value /= 1024;
            $index++;
        }

        return rtrim(rtrim(number_format($value, 1, ',', ' '), '0'), ',').' '.$units[$index];
    }
}
