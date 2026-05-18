<?php

namespace Database\Seeders;

use App\Models\PowerShellPermission;
use Illuminate\Database\Seeder;

class PowerShellPermissionSeeder extends Seeder
{
    public function run(): void
    {
        PowerShellPermission::updateOrCreate(
            ['id' => 1],
            [
                // Global kill switch — disabled by default; user must opt in.
                'is_enabled' => false,
                'allowed_commands' => [
                    'dir', 'ls', 'Get-ChildItem',
                    'Get-Process',
                    'git status', 'git log', 'git diff', 'git branch',
                    'npm list', 'npm outdated',
                    'composer show', 'composer outdated',
                    'php artisan list', 'php artisan route:list',
                    'ping', 'nslookup',
                    'Get-Content',
                    'code',
                ],
                'blocked_paths' => [
                    'C:\\Windows',
                    'C:\\Program Files',
                    'C:\\Program Files (x86)',
                    'C:\\Users\\*\\AppData',
                    'HKLM:',
                    'HKCU:',
                    'HKEY_',
                ],
                'blocked_commands' => [
                    'Remove-Item', 'del', 'rm', 'rmdir', 'rd', 'Clear-Content',
                    'Format-Disk', 'Format-Volume', 'Clear-Disk', 'Format-',
                    'Stop-Process', 'Stop-Service', 'Kill', 'taskkill',
                    'New-LocalUser', 'Remove-LocalUser', 'net user',
                    'Invoke-WebRequest', 'Invoke-RestMethod', 'iwr', 'curl', 'wget',
                    'Set-ExecutionPolicy', 'Invoke-Expression', 'iex',
                ],
                'max_execution_time' => 30,
                'require_approval' => true,
                'log_all_commands' => true,
            ]
        );
    }
}
