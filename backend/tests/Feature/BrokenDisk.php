<?php

namespace Tests\Feature;

/**
 * Panne de disque simulee pour les tests de comptabilite du stockage.
 *
 * Trois modes, parce que les echecs n'ont pas la meme signature :
 *  - `throws = true`  : le seau est injoignable, l'appel leve une exception ;
 *  - `throws = false` : le seau repond `false`, sans exception ;
 *  - `failsDelete`    : la suppression renvoie `false`
 *                       (objet peut-etre toujours present cote bucket).
 *
 * Un driver minimal suffit : `UploadedFile::storeAs()` n'appelle que
 * `putFileAs()`, et `MaterialStorageService` n'appelle que `delete()`.
 */
class BrokenDisk
{
    public function __construct(
        private readonly bool $throws,
        private readonly bool $failsDelete = false,
    ) {}

    public function putFileAs(string $path, mixed $file, string $name, array $options = []): bool
    {
        if ($this->throws) {
            throw new DiskUnavailable('R2 indisponible.');
        }

        return false;
    }

    public function delete($paths): bool
    {
        return ! $this->failsDelete;
    }

    public function exists($path): bool
    {
        return false;
    }
}
