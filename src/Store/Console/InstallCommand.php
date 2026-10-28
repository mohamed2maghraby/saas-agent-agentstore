<?php

namespace SaasAgent\AgentStore\Store\Console;

use Illuminate\Console\Command;

/** Publishes the config and prints what the store still has to do. */
final class InstallCommand extends Command
{
    protected $signature = 'agentstore:install {--force : Overwrite an existing config/agentstore.php}';

    protected $description = 'Publish the SaaS Agent store integration config and show the next steps';

    public function handle(): int
    {
        $this->call('vendor:publish', ['--tag' => 'agentstore-config', '--force' => (bool) $this->option('force')]);

        $this->components->info('Next steps');
        $this->line('  1. Set AGENTSTORE_SAAS_URL in .env to the SaaS Agent address (https).');
        $this->line('  2. Run `php artisan migrate` — the package adds agentstore_* tables only.');
        $this->line('  3. Write adapters for what your store supports and list them under');
        $this->line('     `adapters` in config/agentstore.php. See the package README.');
        $this->line('  4. Decide who may approve a connection, e.g. in a service provider:');
        $this->line("       Gate::define('agentstore-approve', fn (\$user) => \$user->isAdmin());");
        $this->line('  5. Make sure the scheduler runs (`schedule:run` every minute).');
        $this->line('  6. Run `php artisan agentstore:doctor`.');

        return self::SUCCESS;
    }
}
