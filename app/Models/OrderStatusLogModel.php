<?php

namespace App\Models;

use CodeIgniter\Model;

class OrderStatusLogModel extends Model
{
    protected $table            = 'order_status_log';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = ['order_id', 'previous_status', 'new_status', 'log_time'];

    // Callbacks to ensure log_time is added
    protected $allowCallbacks = true;
    protected $beforeInsert   = ['setLogTime'];

    protected function setLogTime(array $data)
    {
        if (!isset($data['data']['log_time'])) {
            $data['data']['log_time'] = date('Y-m-d H:i:s');
        }
        return $data;
    }
}
