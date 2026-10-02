<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Création du super administrateur.
 *
 * §16 « Fuite de secrets : variables d'environnement […] » : le mot de
 * passe n'existe pas dans ClassLink. La commande affiche une URL de
 * connexion Microsoft et un moyen d'activer la réception par code.
 */
class MakeSuperAdminCommand extends Command
{
    protected $signature = 'classlink:make-super-admin
                            {email : Adresse e-mail scolaire (domaine @ofppt-edu.ma)}
                            {--name= : Nom d\'affichage}';

    protected $description = 'Crée ou promeut un compte super administrateur ClassLink.';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));

        if (! str_ends_with($email, '@ofppt-edu.ma')) {
            $this->error('L\'adresse doit appartenir au domaine @ofppt-edu.ma (RG-01).');

            return self::FAILURE;
        }

        $user = User::firstOrNew(['email' => $email]);

        $user->fill([
            'display_name' => $this->option('name') ?: $user->display_name ?: 'Administrateur ClassLink',
            'role' => Role::Admin->value,
            // RG-03 : un rôle attribué par l'admin est verrouillé.
            'role_locked' => true,
            'is_active' => true,
        ]);

        $user->save();

        $this->info("Super administrateur prêt : {$user->email}");
        $this->line('Connectez-vous avec Microsoft, ou demandez un code à usage unique.');
        $this->line('Frontend : '.config('classlink.frontend_url').'/login');

        return self::SUCCESS;
    }
}
