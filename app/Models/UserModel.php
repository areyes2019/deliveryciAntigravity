<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Modelo de usuarios del sistema.
 *
 * Gestiona los tres tipos de usuario: superadmin, client_admin y driver.
 * Todos los usuarios comparten esta tabla; el campo `role` determina
 * qué puede hacer cada uno en la aplicación.
 *
 * Comportamientos automáticos:
 * - Genera un UUID único antes de cada INSERT.
 * - Hashea la contraseña con password_hash() antes de INSERT y UPDATE,
 *   por lo que nunca se almacena la contraseña en texto plano.
 * - Soft deletes activados: al borrar un usuario se rellena `deleted_at`
 *   en lugar de eliminarlo físicamente, preservando la integridad referencial.
 */
class UserModel extends Model
{
    protected $table            = 'users';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = true;
    protected $protectFields    = true;
    protected $allowedFields    = ['uuid', 'name', 'email', 'password', 'role', 'is_suspended'];

    protected bool $allowEmptyInserts = false;

    // Dates
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
    protected $deletedField  = 'deleted_at';

    // Callbacks
    protected $allowCallbacks = true;
    protected $beforeInsert   = ['generateUuid', 'hashPassword'];
    protected $beforeUpdate   = ['hashPassword'];

    /**
     * Genera y asigna un UUID v4 al usuario antes de insertarlo.
     * Solo actúa si el UUID no fue proporcionado manualmente.
     */
    protected function generateUuid(array $data)
    {
        if (!isset($data['data']['uuid'])) {
            $data['data']['uuid'] = sprintf( '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),
                mt_rand( 0, 0xffff ),
                mt_rand( 0, 0x0fff ) | 0x4000,
                mt_rand( 0, 0x3fff ) | 0x8000,
                mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff )
            );
        }
        return $data;
    }

    /**
     * Hashea la contraseña antes de guardarla en la base de datos.
     * Se ejecuta tanto en INSERT como en UPDATE.
     * Si el campo `password` no está presente en los datos, no hace nada.
     */
    protected function hashPassword(array $data)
    {
        if (isset($data['data']['password'])) {
            $data['data']['password'] = password_hash($data['data']['password'], PASSWORD_DEFAULT);
        }
        return $data;
    }
}
