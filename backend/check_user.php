<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$user = \App\Models\User::where('email', 'kayembaemmauel@gmail.com')->first();

if (!$user) {
    echo "User not found. Listing all users:\n\n";
    $users = \App\Models\User::all(['id', 'email', 'created_at']);
    foreach ($users as $u) {
        echo "{$u->email} (ID: {$u->id}) - Created: {$u->created_at}\n";
    }
    exit;
}

echo "User: {$user->email} (ID: {$user->id})\n\n";

$assignments = \App\Models\UserRoleAssignment::with('role.permissions')
    ->where('user_id', $user->id)
    ->get();

if ($assignments->isEmpty()) {
    echo "No role assignments found\n";
} else {
    foreach ($assignments as $assignment) {
        echo "Role: {$assignment->role->code} ({$assignment->role->label})\n";
        echo "Scope: {$assignment->scope_type}\n";
        echo "Permissions:\n";
        foreach ($assignment->role->permissions as $perm) {
            echo "  - {$perm->code}\n";
        }
        echo "\n";
    }
}
