<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/** First login on a fresh production install (no demo data there): creates or promotes a Super Admin. */
class CreateSuperAdminCommand extends Command
{
    protected $signature = 'users:create-admin {phone : Mobile number, e.g. +97336000001} {name : Full name}
        {--password= : At least 10 characters (asked when omitted)} {--email=} {--gender=male : male or female}';

    protected $description = 'Create (or promote) a Super Admin account';

    public function handle(): int
    {
        $phone = PhoneNumber::normalize((string) $this->argument('phone'));
        $password = (string) ($this->option('password') ?: $this->secret('Password (10+ characters)'));
        $v = Validator::make(
            ['phone' => $phone, 'name' => $this->argument('name'), 'password' => $password, 'email' => $this->option('email'), 'gender' => $this->option('gender')],
            ['phone' => ['required'], 'name' => ['required', 'string', 'max:150'], 'password' => ['required', 'string', 'min:10'], 'email' => ['nullable', 'email'], 'gender' => ['in:male,female']],
        );
        if ($v->fails()) {
            foreach ($v->errors()->all() as $e) {
                $this->error($e);
            }

            return self::FAILURE;
        }

        $user = User::updateOrCreate(['phone' => $phone], [
            'name' => $this->argument('name'), 'password' => $password, 'email' => $this->option('email'),
            'gender' => $this->option('gender'), 'track' => 'both', 'locale' => 'ar', 'is_active' => true,
        ]);
        $user->syncRoles(['super_admin']);
        $this->info("Super Admin ready: {$user->name} ({$phone})");

        return self::SUCCESS;
    }
}
