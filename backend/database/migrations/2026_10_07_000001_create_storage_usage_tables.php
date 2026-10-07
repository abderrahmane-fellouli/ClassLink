<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Comptabilite du stockage des fichiers (plafond R2).
 *
 * Deux tables, deux roles distincts :
 *
 * 1. `storage_usage` : **une seule ligne**, compteur d'autorite. Elle porte
 *    `reserved_bytes` (deposes en cours, deja bloquees) et `used_bytes`
 *    (fichiers reellement enregistres). Toutes les reservations passent par
 *    un `UPDATE ... WHERE used + reserved + :n <= :limit` atomique : sous
 *    PostgreSQL, deux requetes concurrentes se serialisent sur cette ligne et
 *    la seconde voit la version fraichement ecrite, donc jamais deux depots
 *    simultanes ne franchissent le plafond lors des ecritures normales.
 *    La reprise apres arret brutal est documentee dans docs/DEPLOYMENT.md.
 *
 * 2. `storage_objects` : registre des objets geres par ClassLink. Il sert a
 *    la fois de trace d'audit et de reference de reconciliation : la commande
 *    `classlink:storage-reconcile` recalcule `used_bytes` par `SUM` sur cette
 *    table (une agregation indexee, pas un parcours du bucket R2) et purge les
 *    lignes dont l'objet a disparu. Un objet absent du registre necessite
 *    un inventaire cote bucket et n'est pas decouvert par cette commande.
 *
 * Le registre demarre vide : le compteur represente ce que ClassLink a ecrit
 * depuis l'installation du garde-fou, pas un inventaire historique du bucket.
 * Les objets anterieurs a cette migration ne sont donc pas comptes. C'est
 * assume : reconstituer un total retroactif fiable demanderait de parcourir le
 * bucket, exactement ce qu'on veut eviter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storage_usage', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('reserved_bytes')->default(0);
            $table->unsignedBigInteger('used_bytes')->default(0);
            $table->timestamps();
        });

        // Une seule ligne : le `id` est fige a 1, ce qui donne au compteur un
        // point de contention unique et connu (pas de "ligne courante" a
        // rechercher, donc aucune possibilite de creer un second compteur).
        DB::table('storage_usage')->insert([
            'id' => 1,
            'reserved_bytes' => 0,
            'used_bytes' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::create('storage_objects', function (Blueprint $table) {
            $table->id();
            $table->string('disk', 64);
            $table->string('path', 512);
            // Le registre est la source de verite du compteur : on y conserve
            // la taille annoncee par le fichier depose, pas la taille lue
            // apres coup dans le bucket (qui peut diverger, cf. la commande
            // de reconciliation).
            $table->unsignedBigInteger('size_bytes');
            $table->string('kind', 32)->default('material');
            $table->string('owner_type', 191)->nullable();
            $table->string('owner_id', 64)->nullable();
            $table->timestamps();

            $table->unique(['disk', 'path'], 'storage_objects_disk_path_unique');
            $table->index(['owner_type', 'owner_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_objects');
        Schema::dropIfExists('storage_usage');
    }
};
