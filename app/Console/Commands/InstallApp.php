<?php

namespace App\Console\Commands;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\RewardBudget;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class InstallApp extends Command
{
    protected $signature = 'app:install
        {--name= : Super administrator name}
        {--email= : Super administrator e-mail}
        {--password= : Super administrator password (prompted when omitted)}
        {--seed : Create the example missions}';

    protected $description = 'Create the first super administrator and initial records';

    public function handle(): int
    {
        RewardBudget::current();

        if (Admin::query()->where('role', AdminRole::SuperAdmin)->exists() && ! $this->option('email')) {
            $this->info('A super administrator already exists. Use --email to add another.');
        } else {
            $name = $this->option('name') ?: $this->ask('Administrator name', 'Owner');
            $email = $this->option('email') ?: $this->ask('Administrator e-mail');
            $password = $this->option('password') ?: $this->secret('Password (min. 12 characters)');

            $validator = Validator::make(compact('name', 'email', 'password'), [
                'name' => ['required', 'string', 'max:120'],
                'email' => ['required', 'email', 'max:190', 'unique:admins,email'],
                'password' => ['required', Password::min(12)->letters()->numbers()],
            ]);

            if ($validator->fails()) {
                foreach ($validator->errors()->all() as $error) {
                    $this->error($error);
                }

                return self::FAILURE;
            }

            Admin::query()->create([
                'name' => $name,
                'email' => strtolower($email),
                'password' => $password,
                'role' => AdminRole::SuperAdmin,
                'is_active' => true,
            ]);

            $this->info("Super administrator {$email} created. Sign in at ".url('/admin'));
        }

        if ($this->option('seed')) {
            $this->call('db:seed', ['--class' => 'Database\\Seeders\\MissionSeeder', '--force' => true]);
        }

        $this->newLine();
        $this->line('Next steps:');
        $this->line('  1. Fund the reward budget in Admin → Budget (rewards stop when it is empty).');
        $this->line('  2. Run "php artisan telegram:setup" (or Admin → Telegram) to register the webhook.');
        $this->line('  3. Add the cron entry: * * * * * php '.base_path('artisan').' schedule:run');

        return self::SUCCESS;
    }
}
