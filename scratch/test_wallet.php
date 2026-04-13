<?php

// Manually load CI4 if needed, or just create a test controller.
// I'll create a scratch script that uses the Database directly to check the sum.

require_once 'app/Config/Paths.php';
require_once 'system/Test/bootstrap.php';

$db = \Config\Database::connect();
$driverId = 1; // Change to a valid driver ID if possible

$query = $db->table('wallet_movements')
            ->where('driver_id', $driverId)
            ->selectSum('amount')
            ->get();

$result = $query->getRowArray();
print_r($result);
