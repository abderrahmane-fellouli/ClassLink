<?php

namespace App\Console\Commands;

use App\Services\StorageUsageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Reparation et inspection du compteur de stockage.
 *
 * Usage :
 *   php artisan classlink:storage-reconcile            # recalcule et affiche
 *   php artisan classlink:storage-reconcile --prune    # + purge les orphelins
 *   php artisan classlink:storage-reconcile --check    # sortie != 0 si derive
 *   php artisan classlink:storage-reconcile --release-stale-reservations
 *
 * Le recalcul est un `SUM` sur `storage_objects` : quelques milliers de lignes,
 * pas un listing du bucket. C'est ce qui permet de reappliquer le plafond sans
 * jamais dependre de l'etat du seau distant.
 */
class StorageReconcileCommand extends Command
{
    protected $signature = 'classlink:storage-reconcile
                            {--prune : Purge du registre les objets absents du disque}
                            {--check : Code de sortie 1 si le compteur a derive (CI)}
                            {--release-stale-reservations : Remet les reservations orphelines a zero (maintenance fermee)}';

    protected $description = "Recalcule le compteur d'occupation du stockage a partir du registre.";

    public function handle(StorageUsageService $usage): int
    {
        if (! $this->releaseStaleReservations($usage)) {
            return self::FAILURE;
        }

        $before = [
            'used' => $usage->usedBytes(),
            'reserved' => $usage->reservedBytes(),
        ];

        if ($this->option('prune')) {
            $this->pruneOrphans($usage);
        }

        $after = $usage->reconcile();
        $limit = $usage->limitBytes();
        $drifted = $before['used'] !== $after['used'];

        $this->line(sprintf('  limite      : %s (%d octets)', $this->format($limit), $limit));
        $this->line(sprintf('  occupe      : %s (%d octets)', $this->format($after['used'] + $after['reserved']), $after['used'] + $after['reserved']));
        $this->line(sprintf('  fichiers    : %s (%d octets, %d objet(s))', $this->format($after['used']), $after['used'], $after['objects']));
        $this->line(sprintf('  reservations: %s (%d octets)', $this->format($after['reserved']), $after['reserved']));

        if ($drifted) {
            $this->warn(sprintf(
                '  derive detectee : %d -> %d octets (le registre fait foi).',
                $before['used'],
                $after['used']
            ));
        } else {
            $this->info('  Compteur coherent avec le registre.');
        }

        if ($limit < $after['used'] + $after['reserved']) {
            $this->error('  ATTENTION : l\'occupation depasse le plafond (reduisez ou augmentez CLASSLINK_STORAGE_LIMIT_BYTES).');
        }

        if ($this->option('check') && $drifted) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Libere les reservations que plus aucun processus ne revendique.
     *
     * Une reservation n'est un indicateur de sante qu'aussi longtemps que son
     * processus vit : `reconcile()` n'y touche donc jamais, faute de savoir
     * distinguer « occupe en ce moment » de « orphelin ». Cette option tranche
     * dans l'autre sens, et pour cela demande une confirmation : lancer la
     * commande pendant qu'un eleve depose un fichier lui retirerait sa place et
     * laisserait deux depots franchir le plafond.
     *
     * @return bool `false` si l'operateur a renonce
     */
    private function releaseStaleReservations(StorageUsageService $usage): bool
    {
        if (! $this->option('release-stale-reservations')) {
            return true;
        }

        if (! $this->confirm('Aucun depot n\'est-il reellement en cours ? (sinon leur reservation sera volee)', false)) {
            $this->error('  Annule : les reservations n\'ont pas ete modifiees.');

            return false;
        }

        $released = $usage->releaseAllReservations();

        $this->line($released > 0
            ? sprintf('  reservations: %s liberes (orphelins).', $this->format($released))
            : '  reservations: aucune reservation en attente.');

        return true;
    }

    /**
     * Supprime du registre les objets que le disque ne contient plus.
     *
     * `exists()` est appele sur le disque configure, donc le registre ne peut
     * diverger que vers « trop d'objets comptes » — la direction qui rend la
     * capacite inutilisable. Dans l'autre sens (objet present mais absent du
     * registre), seul un listing complet pourrait le detecter ; c'est
     * volontairement exclu, et documente dans docs/DEPLOYMENT.md.
     */
    private function pruneOrphans(StorageUsageService $usage): void
    {
        $disk = (string) config('filesystems.default', 'local');
        $purged = 0;

        DB::table('storage_objects')
            ->where('disk', $disk)
            ->orderBy('id')
            ->chunkById(200, function ($objects) use ($disk, $usage, &$purged) {
                foreach ($objects as $object) {
                    if (! Storage::disk($disk)->exists($object->path)) {
                        $usage->forget($disk, (string) $object->path);
                        $purged++;
                    }
                }
            });

        $this->line($purged > 0
            ? "  registre    : $purged objet(s) absent(s) du disque purge(s)."
            : '  registre    : aucun objet orphelin.');
    }

    private function format(int $bytes): string
    {
        $units = ['o', 'Kio', 'Mio', 'Gio', 'Tio'];
        $index = 0;
        $value = (float) $bytes;

        while ($value >= 1024 && $index < count($units) - 1) {
            $value /= 1024;
            $index++;
        }

        return rtrim(rtrim(number_format($value, 2, ',', ' '), '0'), ',').' '.$units[$index];
    }
}
